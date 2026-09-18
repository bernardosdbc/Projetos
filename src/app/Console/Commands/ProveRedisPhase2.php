<?php

namespace App\Console\Commands;

use App\Contracts\JobQueueInterface;
use App\Jobs\Handlers\HandlerRegistry;
use App\Models\Job;
use App\Services\BackoffCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class ProveRedisPhase2 extends Command
{
    protected $signature = 'jobs:prove-redis
        {--concurrency=200 : Jobs for BRPOPLPUSH concurrency proof}
        {--skip-concurrency : Skip the 200-job drain (workers must be up)}
        {--only-unit : Only retry + recovery proofs (stop workers first)}';

    protected $description = 'Fase 2 proofs: concurrency, delayed ZSET retry, stuck recovery';

    public function handle(JobQueueInterface $queue, HandlerRegistry $handlers): int
    {
        if (! in_array(config('jobs.driver'), ['redis', 'redis_streams'], true)) {
            $this->error('QUEUE_DRIVER must be redis or redis_streams.');

            return self::FAILURE;
        }

        if (! $this->option('only-unit') && ! $this->option('skip-concurrency')) {
            $this->proveConcurrency((int) $this->option('concurrency'));
        }

        $this->proveRetryBackoff($queue, $handlers);
        $this->proveStuckRecovery($queue, $handlers);

        $this->info('All Redis phase-2 proofs passed.');

        return self::SUCCESS;
    }

    private function proveConcurrency(int $count): void
    {
        $this->info("Concurrency: seeding {$count} jobs (workers must be running)...");
        $this->call('jobs:seed-concurrency', ['count' => $count, '--fresh' => true]);

        $deadline = time() + 180;
        while (time() < $deadline) {
            $pending = Job::query()->whereIn('status', ['pending', 'processing'])->count();
            if ($pending === 0) {
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

        if ($completed !== $count || $dupes !== 0 || $workers < 1) {
            throw new \RuntimeException('Concurrency proof failed.');
        }

        $this->info('Concurrency proof OK.');
    }

    private function proveRetryBackoff(JobQueueInterface $queue, HandlerRegistry $handlers): void
    {
        $this->info('Retry/backoff via delayed ZSET...');
        config(['jobs.backoff_base' => 2]);

        $key = 'prove-retry-'.uniqid();
        $job = $queue->enqueue('send_email', ['to' => 'retry@example.com', 'fail_times' => 2], $key);

        for ($loop = 1; $loop <= 3; $loop++) {
            $this->promoteJobNow((int) $job->id);

            $claimed = $queue->claimNextJob('prove-retry');
            if ($claimed === null || (int) $claimed->id !== (int) $job->id) {
                throw new \RuntimeException("Retry claim failed on loop {$loop}");
            }

            try {
                $handlers->for($claimed->type)->handle($claimed);
                $queue->markCompleted($claimed);
                $claimed->refresh();
                $this->line("loop{$loop}:completed attempts={$claimed->attempts}");
            } catch (\Throwable $e) {
                $queue->markFailedOrDead($claimed, $e);
                $claimed->refresh();
                $score = Redis::zscore('jobs:delayed', (string) $claimed->id);
                $expected = BackoffCalculator::secondsFor($claimed->attempts);
                $this->line("loop{$loop}:status={$claimed->status} attempts={$claimed->attempts} backoff={$expected}s delayed_score=".($score ?? 'null'));

                if ($claimed->status !== 'pending' || $score === null) {
                    throw new \RuntimeException('Expected pending job in delayed ZSET.');
                }
            }
        }

        $job->refresh();
        if ($job->status !== 'completed' || (int) $job->attempts !== 3) {
            throw new \RuntimeException('Retry proof did not complete on attempt 3.');
        }

        $this->info('Retry/backoff proof OK.');
    }

    private function proveStuckRecovery(JobQueueInterface $queue, HandlerRegistry $handlers): void
    {
        $this->info('Stuck recovery (Redis meta)...');

        $key = 'prove-stuck-'.uniqid();
        $job = $queue->enqueue('send_email', ['to' => 'stuck@example.com', 'sleep_seconds' => 1], $key);
        $claimed = $queue->claimNextJob('prove-stuck');
        if ($claimed === null) {
            throw new \RuntimeException('Stuck proof: initial claim failed.');
        }

        $attemptsAtReserve = (int) $claimed->attempts;

        if (config('jobs.driver') === 'redis_streams') {
            sleep(2);
            $recovered = $queue->recoverStuckJobs(1);
        } else {
            Redis::hset('jobs:meta:'.$claimed->id, 'reserved_at', (string) (time() - 120));
            $recovered = $queue->recoverStuckJobs(30);
        }
        $claimed->refresh();

        $this->line("recovered={$recovered} status={$claimed->status} attempts={$claimed->attempts}");

        if ($recovered < 1 || $claimed->status !== 'pending' || (int) $claimed->attempts !== $attemptsAtReserve) {
            throw new \RuntimeException('Recovery must requeue without bumping attempts.');
        }

        $again = $queue->claimNextJob('prove-stuck-2');
        if ($again === null || (int) $again->id !== (int) $job->id) {
            throw new \RuntimeException('Recovery claim failed.');
        }

        if ((int) $again->attempts !== $attemptsAtReserve + 1) {
            throw new \RuntimeException('Second claim must increment attempts.');
        }

        $handlers->for($again->type)->handle($again);
        $queue->markCompleted($again);
        $again->refresh();

        if ($again->status !== 'completed') {
            throw new \RuntimeException('Stuck recovery job did not complete.');
        }

        $this->info('Stuck recovery proof OK.');
    }

    private function promoteJobNow(int $jobId): void
    {
        Redis::zrem('jobs:delayed', (string) $jobId);
        Job::query()->whereKey($jobId)->update([
            'status' => 'pending',
            'available_at' => now(),
            'reserved_at' => null,
            'reserved_by' => null,
        ]);

        if (config('jobs.driver') === 'redis_streams') {
            $prefix = (string) config('database.redis.options.prefix', '');
            $stream = $prefix.'jobs:stream';
            $client = Redis::connection()->client();
            $messageId = Redis::get('jobs:stream:msg:'.$jobId);
            if ($messageId) {
                $client->executeRaw(['XACK', $stream, 'workers', $messageId]);
                Redis::del('jobs:stream:msg:'.$jobId);
            }
            $client->executeRaw(['XADD', $stream, '*', 'job_id', (string) $jobId]);

            return;
        }

        Redis::lrem('jobs:ready', 0, (string) $jobId);
        Redis::lrem('jobs:processing', 0, (string) $jobId);
        Redis::del('jobs:meta:'.$jobId);
        Redis::lpush('jobs:ready', (string) $jobId);
    }
}
