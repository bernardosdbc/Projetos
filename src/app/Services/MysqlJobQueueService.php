<?php

namespace App\Services;

use App\Contracts\JobQueueInterface;
use App\Enums\JobPriority;
use App\Models\Job;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class MysqlJobQueueService implements JobQueueInterface
{
    public function enqueue(string $type, array $payload, string $idempotencyKey, JobPriority $priority = JobPriority::Normal, ?\DateTimeInterface $executeAt = null): Job
    {
        try {
            return Job::create([
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
    }

    public function claimNextJob(string $workerId): ?Job
    {
        return DB::transaction(function () use ($workerId): ?Job {
            $query = Job::query()
                ->where('status', 'pending')
                ->where('available_at', '<=', now())
                // ENUM column sorts by declaration order (App\Enums\JobPriority),
                // so this is a plain index-backed sort — critical claimed first.
                ->orderBy('priority')
                ->orderBy('available_at');

            // QUEUE_CLAIM_LOCK=false is only for the negative calibration in PLANO §9.2.
            if (filter_var(env('QUEUE_CLAIM_LOCK', true), FILTER_VALIDATE_BOOLEAN)) {
                $query->lockForUpdate();
            }

            $job = $query->first();

            if ($job === null) {
                return null;
            }

            // Widen the race window when locking is off (PLANO §9.2 calibration only).
            if (! filter_var(env('QUEUE_CLAIM_LOCK', true), FILTER_VALIDATE_BOOLEAN)) {
                usleep(100_000);
            }

            $job->update([
                'status' => 'processing',
                'reserved_at' => now(),
                'reserved_by' => $workerId,
                'attempts' => $job->attempts + 1,
            ]);

            return $job;
        });
    }

    public function markCompleted(Job $job): void
    {
        $job->update(['status' => 'completed', 'completed_at' => now()]);
    }

    public function markFailedOrDead(Job $job, \Throwable $exception): void
    {
        DB::transaction(function () use ($job, $exception): void {
            $job->refresh();
            $job->last_error = substr($exception->getMessage(), 0, 2000);

            if ($job->attempts >= $job->max_attempts) {
                $job->status = 'dead';
            } else {
                $job->status = 'pending';
                $job->available_at = now()->addSeconds(BackoffCalculator::secondsFor($job->attempts));
            }

            $job->save();
        });
    }

    public function recoverStuckJobs(int $timeoutSeconds = 120): int
    {
        return Job::query()
            ->where('status', 'processing')
            ->where('reserved_at', '<', now()->subSeconds($timeoutSeconds))
            ->update(['status' => 'pending', 'available_at' => now()]);
    }

    public function requeue(Job $job): void
    {
        // No-op: claimNextJob() polls the jobs table directly by
        // (status, priority, available_at) — there's no external index to update.
    }
}
