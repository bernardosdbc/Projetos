<?php

namespace App\Services;

use App\Contracts\JobQueueInterface;
use App\Enums\JobPriority;
use App\Models\Job;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Redis;

/**
 * Fase 2 — fila em Redis.
 *
 * MySQL continua como registro durável (API / stats / idempotency_key).
 * Redis decide quem está ready / delayed / processing:
 *  - jobs:ready:{priority}  LIST   um por nível (claim via RPOPLPUSH → jobs:processing)
 *  - jobs:delayed           ZSET   score = unix available_at, member = "{priority}:{id}"
 *  - jobs:processing        LIST   (jobs reservados, sem distinção de prioridade)
 *  - jobs:meta:{id}         HASH   reserved_at, reserved_by, priority
 *
 * Prioridade é decidida só pelo Redis (qual lista o claim varre primeiro);
 * MySQL guarda a coluna `priority` apenas como registro durável/API.
 */
class RedisJobQueueService implements JobQueueInterface
{
    private const READY_PREFIX = 'jobs:ready:';
    private const DELAYED = 'jobs:delayed';
    private const PROCESSING = 'jobs:processing';

    /** Ordem de varredura do claim — deve bater com App\Enums\JobPriority. */
    private const PRIORITIES = ['critical', 'high', 'normal', 'low'];

    public function enqueue(string $type, array $payload, string $idempotencyKey, JobPriority $priority = JobPriority::Normal, ?\DateTimeInterface $executeAt = null): Job
    {
        try {
            $job = Job::create([
                'type' => $type,
                'payload' => $payload,
                'idempotency_key' => $idempotencyKey,
                'status' => 'pending',
                'priority' => $priority,
                'available_at' => $executeAt ?? now(),
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
        $this->promoteDelayed();

        foreach (self::PRIORITIES as $priority) {
            $jobId = Redis::rpoplpush($this->readyKey($priority), self::PROCESSING);

            if ($jobId !== null && $jobId !== false) {
                $job = $this->reserve((int) $jobId, $workerId);
                if ($job !== null) {
                    return $job;
                }
            }
        }

        // Idle: block briefly on the lowest-priority list instead of busy-spinning.
        // A higher-priority job that arrives mid-block waits out this 1s timeout
        // before the next claimNextJob() sweep picks it up — bounded latency,
        // acceptable at this project's scale (not a sub-second-SLA system).
        $jobId = Redis::brpoplpush($this->readyKey('low'), self::PROCESSING, 1);

        if ($jobId === null || $jobId === false) {
            return null;
        }

        return $this->reserve((int) $jobId, $workerId);
    }

    public function markCompleted(Job $job): void
    {
        $this->forgetProcessing((int) $job->id);
        $job->update(['status' => 'completed', 'completed_at' => now()]);
    }

    public function markFailedOrDead(Job $job, \Throwable $exception): void
    {
        $job->refresh();
        $job->last_error = substr($exception->getMessage(), 0, 2000);

        $this->forgetProcessing((int) $job->id);

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
        $this->promoteDelayed();

        $recovered = 0;
        $ids = Redis::lrange(self::PROCESSING, 0, -1) ?: [];

        foreach ($ids as $rawId) {
            $jobId = (int) $rawId;
            $reservedAt = (int) (Redis::hget($this->metaKey($jobId), 'reserved_at') ?: 0);

            if ($reservedAt === 0 || (time() - $reservedAt) < $timeoutSeconds) {
                continue;
            }

            // Read priority from meta before forgetProcessing() deletes the hash.
            $priority = (string) (Redis::hget($this->metaKey($jobId), 'priority') ?: JobPriority::Normal->value);

            $this->forgetProcessing($jobId);
            Redis::lpush($this->readyKey($priority), (string) $jobId);

            Job::query()
                ->whereKey($jobId)
                ->where('status', 'processing')
                ->update([
                    'status' => 'pending',
                    'available_at' => now(),
                ]);

            $recovered++;
        }

        return $recovered;
    }

    public function requeue(Job $job): void
    {
        $this->schedule((int) $job->id, $job->priority->value, $job->available_at?->getTimestamp() ?? time());
    }

    private function reserve(int $jobId, string $workerId): ?Job
    {
        $job = Job::query()->find($jobId);

        if ($job === null || $job->status === 'completed' || $job->status === 'dead') {
            $this->forgetProcessing($jobId);

            return null;
        }

        $job->update([
            'status' => 'processing',
            'reserved_at' => now(),
            'reserved_by' => $workerId,
            'attempts' => $job->attempts + 1,
        ]);

        Redis::hmset($this->metaKey($jobId), [
            'reserved_at' => (string) time(),
            'reserved_by' => $workerId,
            'priority' => $job->priority->value,
        ]);

        return $job->fresh();
    }

    private function promoteDelayed(): int
    {
        $now = (string) time();
        $ids = Redis::zrangebyscore(self::DELAYED, '-inf', $now) ?: [];
        $moved = 0;

        foreach ($ids as $member) {
            if ((int) Redis::zrem(self::DELAYED, $member) === 1) {
                [$priority, $jobId] = explode(':', (string) $member, 2);
                Redis::lpush($this->readyKey($priority), $jobId);
                $moved++;
            }
        }

        return $moved;
    }

    private function schedule(int $jobId, string $priority, int $availableAtUnix): void
    {
        if ($availableAtUnix <= time()) {
            Redis::lpush($this->readyKey($priority), (string) $jobId);

            return;
        }

        Redis::zadd(self::DELAYED, $availableAtUnix, $priority.':'.$jobId);
    }

    private function forgetProcessing(int $jobId): void
    {
        Redis::lrem(self::PROCESSING, 0, (string) $jobId);
        Redis::del($this->metaKey($jobId));
    }

    private function readyKey(string $priority): string
    {
        return self::READY_PREFIX.$priority;
    }

    private function metaKey(int $jobId): string
    {
        return 'jobs:meta:'.$jobId;
    }
}
