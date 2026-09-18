<?php

namespace App\Contracts;

use App\Enums\JobPriority;
use App\Models\Job;

interface JobQueueInterface
{
    /**
     * $executeAt defaults to now(); a future timestamp schedules the job
     * (PLANO.md roadmap "Agendamento") — it's just the seed for `available_at`,
     * the same column retry backoff already uses, so claim/delayed-set logic
     * needs no separate scheduling path.
     */
    public function enqueue(string $type, array $payload, string $idempotencyKey, JobPriority $priority = JobPriority::Normal, ?\DateTimeInterface $executeAt = null): Job;

    public function claimNextJob(string $workerId): ?Job;

    public function markCompleted(Job $job): void;

    public function markFailedOrDead(Job $job, \Throwable $exception): void;

    public function recoverStuckJobs(int $timeoutSeconds = 120): int;

    /**
     * Push an already-persisted job back into the driver's backing structure
     * (ready list / stream, by its current priority + available_at). MySQL is
     * a no-op — it polls the table directly. Redis/Streams need this after a
     * dead-job retry or any other status reset that happens outside enqueue().
     */
    public function requeue(Job $job): void;
}
