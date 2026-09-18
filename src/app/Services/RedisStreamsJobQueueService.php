<?php

namespace App\Services;

use App\Contracts\JobQueueInterface;
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
 *  - jobs:stream          STREAM
 *  - group "workers"      consumer = --worker-id
 *  - jobs:delayed         ZSET (Facade Redis — ok)
 *  - jobs:stream:msg:{id} STRING message id (Facade)
 */
class RedisStreamsJobQueueService implements JobQueueInterface
{
    private const STREAM = 'jobs:stream';
    private const GROUP = 'workers';
    private const DELAYED = 'jobs:delayed';

    public function enqueue(string $type, array $payload, string $idempotencyKey): Job
    {
        $this->ensureGroup();

        try {
            $job = Job::create([
                'type' => $type,
                'payload' => $payload,
                'idempotency_key' => $idempotencyKey,
                'status' => 'pending',
                'available_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }

            return Job::where('idempotency_key', $idempotencyKey)->firstOrFail();
        }

        $this->schedule((int) $job->id, $job->available_at?->getTimestamp() ?? time());

        return $job;
    }

    public function claimNextJob(string $workerId): ?Job
    {
        $this->ensureGroup();
        $this->promoteDelayed();

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $entries = $this->raw([
                'XREADGROUP', 'GROUP', self::GROUP, $workerId,
                'COUNT', '1', 'BLOCK', $attempt === 0 ? '1000' : '200',
                'STREAMS', $this->streamKey(), '>',
            ]);

            [$messageId, $jobId] = $this->parseFirstEntry($entries);
            if ($messageId === null) {
                return null;
            }
            if ($jobId === null) {
                $this->raw(['XACK', $this->streamKey(), self::GROUP, $messageId]);
                continue;
            }

            $job = Job::query()->find($jobId);
            if ($job === null || $job->status === 'completed' || $job->status === 'dead') {
                $this->raw(['XACK', $this->streamKey(), self::GROUP, $messageId]);
                Redis::del($this->msgKey($jobId));
                continue;
            }

            $job->update([
                'status' => 'processing',
                'reserved_at' => now(),
                'reserved_by' => $workerId,
                'attempts' => $job->attempts + 1,
            ]);

            Redis::set($this->msgKey($jobId), $messageId);

            return $job->fresh();
        }

        return null;
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

        $this->schedule((int) $job->id, $job->available_at->getTimestamp());
    }

    public function recoverStuckJobs(int $timeoutSeconds = 120): int
    {
        $this->ensureGroup();
        $this->promoteDelayed();

        $recovered = 0;
        $minIdleMs = (string) (max(1, $timeoutSeconds) * 1000);

        $result = $this->raw([
            'XAUTOCLAIM', $this->streamKey(), self::GROUP, 'reaper',
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
                $this->raw(['XACK', $this->streamKey(), self::GROUP, $messageId]);
                continue;
            }

            $jobId = (int) $jobId;
            $this->raw(['XACK', $this->streamKey(), self::GROUP, $messageId]);
            Redis::del($this->msgKey($jobId));

            Job::query()
                ->whereKey($jobId)
                ->where('status', 'processing')
                ->update([
                    'status' => 'pending',
                    'available_at' => now(),
                ]);

            $this->raw(['XADD', $this->streamKey(), '*', 'job_id', (string) $jobId]);
            $recovered++;
        }

        return $recovered;
    }

    private function ensureGroup(): void
    {
        $len = $this->raw(['XLEN', $this->streamKey()]);
        if ((int) $len === 0) {
            $this->raw(['XADD', $this->streamKey(), '*', '_init', '1']);
        }

        try {
            $this->raw(['XGROUP', 'CREATE', $this->streamKey(), self::GROUP, '0']);
        } catch (Throwable $exception) {
            if (! str_contains($exception->getMessage(), 'BUSYGROUP')) {
                throw $exception;
            }
        }
    }

    private function schedule(int $jobId, int $availableAtUnix): void
    {
        if ($availableAtUnix <= time()) {
            $this->raw(['XADD', $this->streamKey(), '*', 'job_id', (string) $jobId]);

            return;
        }

        Redis::zadd(self::DELAYED, $availableAtUnix, (string) $jobId);
    }

    private function promoteDelayed(): int
    {
        $ids = Redis::zrangebyscore(self::DELAYED, '-inf', (string) time()) ?: [];
        $moved = 0;

        foreach ($ids as $rawId) {
            if ((int) Redis::zrem(self::DELAYED, $rawId) === 1) {
                $this->raw(['XADD', $this->streamKey(), '*', 'job_id', (string) $rawId]);
                $moved++;
            }
        }

        return $moved;
    }

    private function ack(int $jobId): void
    {
        $messageId = Redis::get($this->msgKey($jobId));
        if ($messageId) {
            $this->raw(['XACK', $this->streamKey(), self::GROUP, $messageId]);
            Redis::del($this->msgKey($jobId));
        }
    }

    private function msgKey(int $jobId): string
    {
        return 'jobs:stream:msg:'.$jobId;
    }

    private function streamKey(): string
    {
        return (string) config('database.redis.options.prefix', '').self::STREAM;
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
