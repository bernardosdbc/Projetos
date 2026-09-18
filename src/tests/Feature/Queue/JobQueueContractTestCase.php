<?php

namespace Tests\Feature\Queue;

use App\Contracts\JobQueueInterface;
use App\Enums\JobPriority;
use App\Models\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\TestCase;

/**
 * Same contract, three drivers. Every test here runs once per concrete
 * subclass (Mysql/Redis/RedisStreams) against identical assertions — the
 * whole point of JobQueueInterface is that a caller can't tell them apart.
 *
 * True multi-process concurrency (two real workers racing the same row) is
 * deliberately out of scope here — that needs actual OS-level races, which
 * belongs to `jobs:prove-redis` / `jobs:prove-negative-lock` (PLANO.md §9),
 * not a single-process PHPUnit run.
 */
abstract class JobQueueContractTestCase extends TestCase
{
    use RefreshDatabase;

    abstract protected function makeQueue(): JobQueueInterface;

    /**
     * Make $job look abandoned to recoverStuckJobs() and return the
     * timeoutSeconds to call it with. Drivers track staleness differently
     * (a DB column, a Redis hash, or Streams' own idle time — which can
     * only be faked with a real short sleep, there's nothing to rewrite).
     */
    abstract protected function makeJobLookStuck(JobQueueInterface $queue, Job $job): int;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::connection()->flushdb();
    }

    /**
     * Skip past a backoff wait without a real sleep: push available_at back
     * to now and let the driver re-index it. Same trick as
     * ProveRedisPhase2::promoteJobNow() and DeadJobController::retry().
     */
    protected function forcePromote(JobQueueInterface $queue, int $jobId): void
    {
        $job = Job::query()->findOrFail($jobId);
        $job->update(['available_at' => now()]);
        $queue->requeue($job);
    }

    public function test_enqueue_is_idempotent_by_key(): void
    {
        $queue = $this->makeQueue();

        $first = $queue->enqueue('send_email', ['to' => 'a@example.com'], 'idem-key-1');
        $second = $queue->enqueue('send_email', ['to' => 'a@example.com'], 'idem-key-1');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Job::query()->where('idempotency_key', 'idem-key-1')->count());
    }

    public function test_claim_returns_null_when_queue_empty(): void
    {
        $queue = $this->makeQueue();

        $this->assertNull($queue->claimNextJob('worker-empty'));
    }

    public function test_claim_orders_by_priority_then_creation_order(): void
    {
        $queue = $this->makeQueue();

        $low1 = $queue->enqueue('send_email', [], 'prio-low-1', JobPriority::Low);
        $low2 = $queue->enqueue('send_email', [], 'prio-low-2', JobPriority::Low);
        $normal = $queue->enqueue('send_email', [], 'prio-normal', JobPriority::Normal);
        $high = $queue->enqueue('send_email', [], 'prio-high', JobPriority::High);
        $critical = $queue->enqueue('send_email', [], 'prio-critical', JobPriority::Critical);

        $order = [];
        for ($i = 0; $i < 5; $i++) {
            $order[] = $queue->claimNextJob('order-worker')?->id;
        }

        $this->assertSame(
            [$critical->id, $high->id, $normal->id, $low1->id, $low2->id],
            $order,
        );
    }

    public function test_execute_at_in_the_past_is_immediately_claimable(): void
    {
        $queue = $this->makeQueue();
        $job = $queue->enqueue('send_email', [], 'sched-past', JobPriority::Normal, now()->subMinute());

        $claimed = $queue->claimNextJob('sched-worker');

        $this->assertNotNull($claimed);
        $this->assertSame($job->id, $claimed->id);
    }

    public function test_execute_at_in_the_future_is_not_yet_claimable(): void
    {
        $queue = $this->makeQueue();
        $queue->enqueue('send_email', [], 'sched-future', JobPriority::Normal, now()->addMinutes(10));

        $this->assertNull($queue->claimNextJob('sched-worker'));
    }

    public function test_mark_completed_sets_status_and_timestamp(): void
    {
        $queue = $this->makeQueue();
        $queue->enqueue('send_email', [], 'complete-1');
        $job = $queue->claimNextJob('complete-worker');

        $queue->markCompleted($job);

        $fresh = Job::query()->find($job->id);
        $this->assertSame('completed', $fresh->status);
        $this->assertNotNull($fresh->completed_at);
    }

    public function test_failure_before_max_attempts_reschedules_with_backoff(): void
    {
        config(['jobs.backoff_base' => 30]);
        $queue = $this->makeQueue();
        $queue->enqueue('send_email', [], 'fail-once');
        $job = $queue->claimNextJob('fail-worker');

        $queue->markFailedOrDead($job, new RuntimeException('boom'));

        $fresh = Job::query()->find($job->id);
        $this->assertSame('pending', $fresh->status);
        $this->assertSame('boom', $fresh->last_error);
        $this->assertTrue($fresh->available_at->isFuture());
        $this->assertEqualsWithDelta(30, abs($fresh->available_at->diffInSeconds(now())), 2);
    }

    public function test_failure_after_max_attempts_reaches_dead(): void
    {
        config(['jobs.backoff_base' => 1]);
        $queue = $this->makeQueue();
        $job = $queue->enqueue('send_email', [], 'fail-to-dead');
        $maxAttempts = $job->max_attempts;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $claimed = $queue->claimNextJob('dead-worker');
            $this->assertNotNull($claimed, "expected a claimable job on attempt {$attempt}");
            $this->assertSame($attempt, $claimed->attempts);

            $queue->markFailedOrDead($claimed, new RuntimeException("boom {$attempt}"));

            if ($attempt < $maxAttempts) {
                $this->forcePromote($queue, $claimed->id);
            }
        }

        $fresh = Job::query()->find($job->id);
        $this->assertSame('dead', $fresh->status);
        $this->assertSame($maxAttempts, $fresh->attempts);
    }

    public function test_requeue_after_dead_retry_is_claimable(): void
    {
        $queue = $this->makeQueue();
        $job = $queue->enqueue('send_email', [], 'dead-retry-1');
        $job->update(['status' => 'dead', 'attempts' => 5]);

        // Mirrors DeadJobController::retry().
        $job->update([
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now(),
            'reserved_at' => null,
            'reserved_by' => null,
        ]);
        $queue->requeue($job->fresh());

        $claimed = $queue->claimNextJob('revive-worker');

        $this->assertNotNull($claimed);
        $this->assertSame($job->id, $claimed->id);
    }

    public function test_recover_stuck_jobs_returns_job_to_pending_without_bumping_attempts(): void
    {
        $queue = $this->makeQueue();
        $queue->enqueue('send_email', [], 'stuck-1');
        $claimed = $queue->claimNextJob('stuck-worker');
        $attemptsAtClaim = $claimed->attempts;

        $timeoutSeconds = $this->makeJobLookStuck($queue, $claimed);
        $recovered = $queue->recoverStuckJobs($timeoutSeconds);

        $this->assertSame(1, $recovered);

        $fresh = Job::query()->find($claimed->id);
        $this->assertSame('pending', $fresh->status);
        $this->assertSame($attemptsAtClaim, $fresh->attempts);

        $reclaimed = $queue->claimNextJob('stuck-worker-2');
        $this->assertNotNull($reclaimed);
        $this->assertSame($claimed->id, $reclaimed->id);
        $this->assertSame($attemptsAtClaim + 1, $reclaimed->attempts);
    }

    public function test_claim_with_type_filter_declines_other_types_without_side_effects(): void
    {
        $queue = $this->makeQueue();
        $email = $queue->enqueue('send_email', [], 'filter-email-1');
        $report = $queue->enqueue('generate_report', [], 'filter-report-1');

        $claimed = $queue->claimNextJob('specialist', ['generate_report']);
        $this->assertNotNull($claimed);
        $this->assertSame($report->id, $claimed->id);

        $untouched = Job::query()->find($email->id);
        $this->assertSame('pending', $untouched->status);
        $this->assertSame(0, $untouched->attempts);

        $this->assertNull($queue->claimNextJob('specialist', ['generate_report']));

        $generalist = $queue->claimNextJob('generalist');
        $this->assertNotNull($generalist);
        $this->assertSame($email->id, $generalist->id);
    }
}
