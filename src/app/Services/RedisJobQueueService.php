<?php

namespace App\Services;

use App\Contracts\JobQueueInterface;
use App\Models\Job;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Redis;

/**
 * Fase 2 — fila em Redis.
 *
 * MySQL continua como registro durável (API / stats / idempotency_key).
 * Redis decide quem está ready / delayed / processing:
 *  - jobs:ready      LIST   (claim via BRPOPLPUSH → jobs:processing)
 *  - jobs:delayed    ZSET   score = unix available_at
 *  - jobs:processing LIST   (jobs reservados)
 *  - jobs:meta:{id}  HASH   reserved_at, reserved_by
 */
class RedisJobQueueService implements JobQueueInterface
{
    private const READY = 'jobs:ready';
    private const DELAYED = 'jobs:delayed';
    private const PROCESSING = 'jobs:processing';

    public function enqueue(string $type, array $payload, string $idempotencyKey): Job
    {
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
        $this->promoteDelayed();

        $jobId = Redis::brpoplpush(self::READY, self::PROCESSING, 1);

        if ($jobId === null || $jobId === false) {
            return null;
        }

        $jobId = (int) $jobId;
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
        ]);

        return $job->fresh();
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

        $this->schedule((int) $job->id, $job->available_at->getTimestamp());
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

            $this->forgetProcessing($jobId);
            Redis::lpush(self::READY, (string) $jobId);

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

    private function promoteDelayed(): int
    {
        $now = (string) time();
        $ids = Redis::zrangebyscore(self::DELAYED, '-inf', $now) ?: [];
        $moved = 0;

        foreach ($ids as $rawId) {
            if ((int) Redis::zrem(self::DELAYED, $rawId) === 1) {
                Redis::lpush(self::READY, (string) $rawId);
                $moved++;
            }
        }

        return $moved;
    }

    private function schedule(int $jobId, int $availableAtUnix): void
    {
        if ($availableAtUnix <= time()) {
            Redis::lpush(self::READY, (string) $jobId);

            return;
        }

        Redis::zadd(self::DELAYED, $availableAtUnix, (string) $jobId);
    }

    private function forgetProcessing(int $jobId): void
    {
        Redis::lrem(self::PROCESSING, 0, (string) $jobId);
        Redis::del($this->metaKey($jobId));
    }

    private function metaKey(int $jobId): string
    {
        return 'jobs:meta:'.$jobId;
    }
}
