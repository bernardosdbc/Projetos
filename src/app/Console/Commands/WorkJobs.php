<?php

namespace App\Console\Commands;

use App\Jobs\Handlers\HandlerRegistry;
use App\Services\JobQueueService;
use Illuminate\Console\Command;

class WorkJobs extends Command
{
    protected $signature = 'jobs:work {--worker-id= : Identifier for this worker} {--sleep=1} {--timeout=120 : Seconds before a processing job is considered stuck}';
    protected $description = 'Process distributed jobs from the MySQL queue';

    public function handle(JobQueueService $queue, HandlerRegistry $handlers): int
    {
        $workerId = $this->option('worker-id') ?: gethostname();
        $timeoutSeconds = max(1, (int) $this->option('timeout'));

        while (true) {
            $queue->recoverStuckJobs($timeoutSeconds);
            $job = $queue->claimNextJob($workerId);

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
}
