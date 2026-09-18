<?php

namespace App\Services;

use App\Contracts\JobQueueInterface;
use App\Enums\JobPriority;
use App\Models\Job;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Fase 2 variante — Redis Streams + consumer group.
 *
 * Predis via Laravel não serializa bem XADD/XREADGROUP com arrays;
 * comandos de stream usam executeRaw com prefixo explícito.
 *
 *  - jobs:stream:{priority}  STREAM  um por nível, mesmo grupo em todos
 *  - group "workers"         consumer = --worker-id
 *  - jobs:delayed             ZSET (Facade Redis — ok), member = "{priority}:{id}"
 *  - jobs:stream:msg:{id}     STRING "{priority}:{message-id}" (Facade)
 *
 * O claim varre os streams em ordem de prioridade (não-bloqueante) e só
 * bloqueia, como último recurso, no stream de menor prioridade — mesma
 * lógica e o mesmo tradeoff de latência do driver `redis` (LIST).
 */
class RedisStreamsJobQueueService implements JobQueueInterface
{
    private const STREAM_PREFIX = 'jobs:stream:';
    private const GROUP = 'workers';
    private const DELAYED = 'jobs:delayed';

    /** Ordem de varredura do claim — deve bater com App\Enums\JobPriority. */
    private const PRIORITIES = ['critical', 'high', 'normal', 'low'];

    public function enqueue(string $type, array $payload, string $idempotencyKey, JobPriority $priority = JobPriority::Normal): Job
    {
        $this->ensureGroup($priority->value);

        try {
            $job = Job::create([
                'type' => $type,
                'payload' => $payload,
                'idempotency_key' => $idempotencyKey,
                'status' => 'pending',
                'priority' => $priority,
                'available_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }

            return Job::where('idempotency_key', $idempotencyKey)->firstOrFail();
        }

        $this->requeue($job);

        return $job;
    }

    public function claimNextJob(string $workerId): ?Job
    {
        $this->ensureGroups();
        $this->promoteDelayed();

        foreach (self::PRIORITIES as $priority) {
            $job = $this->readOne($priority, $workerId, null);
            if ($job !== null) {
                return $job;
            }
        }

        // Idle: block briefly on the lowest-priority stream instead of busy-spinning.
        // Same bounded-latency tradeoff as the LIST driver (see claimNextJob there).
        return $this->readOne('low', $workerId, '1000');
    }

    public function markCompleted(Job $job): void
    {
        $this->ack((int) $job->id);
        $job->update(['status' => 'completed', 'completed_at' => now()]);
    }

    public function markFailedOrDead(Job $job, Throwable $exception): void
    {
        $job->refresh();
        $job->last_error = substr($exception->getMessage(), 0, 2000);
        $this->ack((int) $job->id);

        if ($job->attempts >= $job->max_attempts) {
            $job->status = 'dead';
            $job->save();

            return;
        }

        $job->status = 'pending';
        $job->available_at = now()->addSeconds(BackoffCalculator::secondsFor($job->attempts));
        $job->save();

        $this->requeue($job);
    }

    public function recoverStuckJobs(int $timeoutSeconds = 120): int
    {
        $this->ensureGroups();
        $this->promoteDelayed();

        $recovered = 0;
        $minIdleMs = (string) (max(1, $timeoutSeconds) * 1000);

        foreach (self::PRIORITIES as $priority) {
            $result = $this->raw([
                'XAUTOCLAIM', $this->streamKey($priority), self::GROUP, 'reaper',
                $minIdleMs, '0-0', 'COUNT', '50',
            ]);

            $entries = is_array($result) ? ($result[1] ?? []) : [];
            foreach ($entries as $entry) {
                if (! is_array($entry) || count($entry) < 2) {
                    continue;
                }

                $messageId = (string) $entry[0];
                $jobId = $this->fieldValue($entry[1] ?? [], 'job_id');
                if ($jobId === null) {
                    $this->raw(['XACK', $this->streamKey($priority), self::GROUP, $messageId]);
                    continue;
                }

                $jobId = (int) $jobId;
                $this->raw(['XACK', $this->streamKey($priority), self::GROUP, $messageId]);
                Redis::del($this->msgKey($jobId));

                Job::query()
                    ->whereKey($jobId)
                    ->where('status', 'processing')
                    ->update([
                        'status' => 'pending',
                        'available_at' => now(),
                    ]);

                $this->raw(['XADD', $this->streamKey($priority), '*', 'job_id', (string) $jobId]);
                $recovered++;
            }
        }

        return $recovered;
    }

    public function requeue(Job $job): void
    {
        $this->schedule((int) $job->id, $job->priority->value, $job->available_at?->getTimestamp() ?? time());
    }

    private function readOne(string $priority, string $workerId, ?string $blockMs): ?Job
    {
        // Up to a few attempts to skip past stale entries in THIS stream — the
        // ensureGroup() bootstrap "_init" sentinel, or a message left over from
        // a job that's already completed/dead — before concluding it's genuinely
        // empty. Without this, a single stale entry would make the sweep skip a
        // whole priority level even though a real job is queued right behind it.
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $command = ['XREADGROUP', 'GROUP', self::GROUP, $workerId, 'COUNT', '1'];
            if ($blockMs !== null && $attempt === 0) {
                $command[] = 'BLOCK';
                $command[] = $blockMs;
            }
            $command = [...$command, 'STREAMS', $this->streamKey($priority), '>'];

            $entries = $this->raw($command);
            [$messageId, $jobId] = $this->parseFirstEntry($entries);

            if ($messageId === null) {
                return null;
            }

            if ($jobId === null) {
                $this->raw(['XACK', $this->streamKey($priority), self::GROUP, $messageId]);

                continue;
            }

            $job = Job::query()->find($jobId);
            if ($job === null || $job->status === 'completed' || $job->status === 'dead') {
                $this->raw(['XACK', $this->streamKey($priority), self::GROUP, $messageId]);
                Redis::del($this->msgKey($jobId));

                continue;
            }

            $job->update([
                'status' => 'processing',
                'reserved_at' => now(),
                'reserved_by' => $workerId,
                'attempts' => $job->attempts + 1,
            ]);

            Redis::set($this->msgKey($jobId), $priority.':'.$messageId);

            return $job->fresh();
        }

        return null;
    }

    private function ensureGroups(): void
    {
        foreach (self::PRIORITIES as $priority) {
            $this->ensureGroup($priority);
        }
    }

    private function ensureGroup(string $priority): void
    {
        $stream = $this->streamKey($priority);

        $len = $this->raw(['XLEN', $stream]);
        if ((int) $len === 0) {
            $this->raw(['XADD', $stream, '*', '_init', '1']);
        }

        try {
            $this->raw(['XGROUP', 'CREATE', $stream, self::GROUP, '0']);
        } catch (Throwable $exception) {
            if (! str_contains($exception->getMessage(), 'BUSYGROUP')) {
                throw $exception;
            }
        }
    }

    private function schedule(int $jobId, string $priority, int $availableAtUnix): void
    {
        if ($availableAtUnix <= time()) {
            $this->raw(['XADD', $this->streamKey($priority), '*', 'job_id', (string) $jobId]);

            return;
        }

        Redis::zadd(self::DELAYED, $availableAtUnix, $priority.':'.$jobId);
    }

    private function promoteDelayed(): int
    {
        $ids = Redis::zrangebyscore(self::DELAYED, '-inf', (string) time()) ?: [];
        $moved = 0;

        foreach ($ids as $member) {
            if ((int) Redis::zrem(self::DELAYED, $member) === 1) {
                [$priority, $jobId] = explode(':', (string) $member, 2);
                $this->raw(['XADD', $this->streamKey($priority), '*', 'job_id', $jobId]);
                $moved++;
            }
        }

        return $moved;
    }

    private function ack(int $jobId): void
    {
        $stored = Redis::get($this->msgKey($jobId));
        if (! $stored) {
            return;
        }

        [$priority, $messageId] = explode(':', (string) $stored, 2);
        $this->raw(['XACK', $this->streamKey($priority), self::GROUP, $messageId]);
        Redis::del($this->msgKey($jobId));
    }

    private function msgKey(int $jobId): string
    {
        return 'jobs:stream:msg:'.$jobId;
    }

    private function streamKey(string $priority): string
    {
        return (string) config('database.redis.options.prefix', '').self::STREAM_PREFIX.$priority;
    }

    /**
     * @param  list<string>  $parts
     */
    private function raw(array $parts): mixed
    {
        return Redis::connection()->client()->executeRaw($parts);
    }

    /**
     * @return array{0: ?string, 1: ?int}
     */
    private function parseFirstEntry(mixed $entries): array
    {
        if (! is_array($entries) || $entries === []) {
            return [null, null];
        }

        $streamBlock = $entries[0] ?? null;
        if (! is_array($streamBlock)) {
            return [null, null];
        }

        $messages = $streamBlock[1] ?? null;
        if (! is_array($messages) || $messages === []) {
            return [null, null];
        }

        $first = $messages[0] ?? null;
        if (! is_array($first) || count($first) < 2) {
            return [null, null];
        }

        $messageId = (string) $first[0];
        $jobId = $this->fieldValue($first[1] ?? [], 'job_id');

        return [$messageId, $jobId !== null ? (int) $jobId : null];
    }

    private function fieldValue(mixed $fields, string $name): ?string
    {
        if (! is_array($fields)) {
            return null;
        }

        if (array_is_list($fields)) {
            for ($i = 0; $i + 1 < count($fields); $i += 2) {
                if ((string) $fields[$i] === $name) {
                    return (string) $fields[$i + 1];
                }
            }

            return null;
        }

        return isset($fields[$name]) ? (string) $fields[$name] : null;
    }
}
