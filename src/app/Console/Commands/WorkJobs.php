<?php

namespace App\Console\Commands;

use App\Contracts\JobQueueInterface;
use App\Jobs\Handlers\HandlerRegistry;
use Illuminate\Console\Command;

class WorkJobs extends Command
{
    protected $signature = 'jobs:work {--worker-id= : Identifier for this worker} {--sleep=1} {--timeout=120 : Seconds before a processing job is considered stuck} {--types= : Comma-separated job types this worker claims (default: any)}';
    protected $description = 'Process distributed jobs from the queue';

    public function handle(JobQueueInterface $queue, HandlerRegistry $handlers): int
    {
        $workerId = $this->option('worker-id') ?: gethostname();
        $timeoutSeconds = max(1, (int) $this->option('timeout'));
        $allowedTypes = $this->parseTypes($this->option('types'));

        if ($allowedTypes !== []) {
            $this->info("Worker {$workerId} only claims: ".implode(', ', $allowedTypes));
        }

        while (true) {
            $queue->recoverStuckJobs($timeoutSeconds);
            $job = $queue->claimNextJob($workerId, $allowedTypes);

            if ($job === null) {
                sleep((int) $this->option('sleep'));
                continue;
            }

            try {
                $handlers->for($job->type)->handle($job);
                $queue->markCompleted($job);
            } catch (\Throwable $exception) {
                $queue->markFailedOrDead($job, $exception);
                $this->error($exception->getMessage());
            }
        }
    }

    /**
     * @return list<string>
     */
    private function parseTypes(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
