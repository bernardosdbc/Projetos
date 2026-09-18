<?php

namespace App\Console\Commands;

use App\Services\JobQueueService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedConcurrencyJobs extends Command
{
    protected $signature = 'jobs:seed-concurrency {count=200} {--fresh : Truncate jobs/job_runs/idempotency_receipts first}';
    protected $description = 'Enqueue N send_email jobs for the concurrent-worker locking proof';

    public function handle(JobQueueService $queue): int
    {
        $count = max(1, (int) $this->argument('count'));

        if ($this->option('fresh')) {
            DB::table('job_runs')->truncate();
            DB::table('idempotency_receipts')->truncate();
            DB::table('jobs')->truncate();
            $this->info('Truncated jobs, job_runs and idempotency_receipts.');
        }

        for ($i = 1; $i <= $count; $i++) {
            $queue->enqueue(
                'send_email',
                ['to' => "user{$i}@example.com"],
                "concurrency-{$i}"
            );
        }

        $this->info("Enqueued {$count} jobs.");

        return self::SUCCESS;
    }
}
