<?php

namespace App\Console\Commands;

use App\Contracts\JobQueueInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class SeedConcurrencyJobs extends Command
{
    protected $signature = 'jobs:seed-concurrency {count=200} {--fresh : Truncate jobs/job_runs/idempotency_receipts first}';
    protected $description = 'Enqueue N send_email jobs for the concurrent-worker locking proof';

    public function handle(JobQueueInterface $queue): int
    {
        $count = max(1, (int) $this->argument('count'));

        if ($this->option('fresh')) {
            DB::table('job_runs')->truncate();
            DB::table('idempotency_receipts')->truncate();
            DB::table('jobs')->truncate();
            if (in_array(config('jobs.driver'), ['redis', 'redis_streams'], true)) {
                Redis::flushdb();
            }
            $this->info('Truncated jobs tables'.(in_array(config('jobs.driver'), ['redis', 'redis_streams'], true) ? ' and Redis DB' : '').'.');
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
