<?php

namespace App\Console\Commands;

use App\Models\Job;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProveNegativeLock extends Command
{
    protected $signature = 'jobs:prove-negative-lock {count=200}';
    protected $description = 'PLANO §9.2: with QUEUE_CLAIM_LOCK=false, expect duplicate job_runs';

    public function handle(): int
    {
        if (config('jobs.driver') !== 'mysql') {
            $this->error('Set QUEUE_DRIVER=mysql for this proof.');

            return self::FAILURE;
        }

        if (filter_var(env('QUEUE_CLAIM_LOCK', true), FILTER_VALIDATE_BOOLEAN)) {
            $this->error('Set QUEUE_CLAIM_LOCK=false for this proof.');

            return self::FAILURE;
        }

        $count = max(1, (int) $this->argument('count'));
        $this->warn('Negative lock proof: workers must be running. Expect dupes > 0.');
        $this->call('jobs:seed-concurrency', ['count' => $count, '--fresh' => true]);

        $deadline = time() + 300;
        while (time() < $deadline) {
            $remaining = Job::query()->whereIn('status', ['pending', 'processing'])->count();
            if ($remaining === 0) {
                break;
            }
            sleep(1);
        }

        $completed = Job::query()->where('status', 'completed')->count();
        $dupes = DB::table('job_runs')
            ->select('job_id', DB::raw('COUNT(*) as c'))
            ->groupBy('job_id')
            ->having('c', '>', 1)
            ->count();
        $workers = DB::table('job_runs')->distinct()->count('reserved_by');

        $this->line("completed={$completed} dupes={$dupes} workers={$workers}");

        if ($dupes < 1) {
            $this->error('Expected dupes > 0 without lockForUpdate. Race did not appear — retry or widen usleep.');

            return self::FAILURE;
        }

        $this->info('Negative lock proof OK: lockForUpdate is doing real work when enabled.');

        return self::SUCCESS;
    }
}
