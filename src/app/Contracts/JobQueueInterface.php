<?php

namespace App\Contracts;

use App\Models\Job;

interface JobQueueInterface
{
    public function enqueue(string $type, array $payload, string $idempotencyKey): Job;

    public function claimNextJob(string $workerId): ?Job;

    public function markCompleted(Job $job): void;

    public function markFailedOrDead(Job $job, \Throwable $exception): void;

    public function recoverStuckJobs(int $timeoutSeconds = 120): int;
}
