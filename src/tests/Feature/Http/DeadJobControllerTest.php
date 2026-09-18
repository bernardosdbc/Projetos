<?php

namespace Tests\Feature\Http;

use App\Contracts\JobQueueInterface;
use App\Models\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class DeadJobControllerTest extends TestCase
{
    use RefreshDatabase;

    private function useDriver(string $driver): void
    {
        config(['jobs.driver' => $driver]);
        $this->app->forgetInstance(JobQueueInterface::class);
    }

    public function test_index_lists_only_dead_jobs(): void
    {
        $pendingId = $this->postJson('/api/jobs', [
            'type' => 'send_email', 'payload' => ['to' => 'a@x.com'], 'idempotency_key' => 'dj-pending',
        ])->json('id');

        $deadId = $this->postJson('/api/jobs', [
            'type' => 'send_email', 'payload' => ['to' => 'b@x.com'], 'idempotency_key' => 'dj-dead',
        ])->json('id');
        Job::query()->whereKey($deadId)->update(['status' => 'dead']);

        $response = $this->getJson('/api/dead-jobs');

        $response->assertOk();
        $ids = collect($response->json())->pluck('id');
        $this->assertTrue($ids->contains($deadId));
        $this->assertFalse($ids->contains($pendingId));
    }

    public function test_retry_returns_409_when_job_is_not_dead(): void
    {
        $id = $this->postJson('/api/jobs', [
            'type' => 'send_email', 'payload' => ['to' => 'a@x.com'], 'idempotency_key' => 'dj-notdead',
        ])->json('id');

        $response = $this->postJson("/api/dead-jobs/{$id}/retry");

        $response->assertStatus(409);
    }

    public function test_retry_revives_a_dead_job(): void
    {
        $id = $this->postJson('/api/jobs', [
            'type' => 'send_email', 'payload' => ['to' => 'a@x.com'], 'idempotency_key' => 'dj-revive',
        ])->json('id');
        Job::query()->whereKey($id)->update(['status' => 'dead', 'attempts' => 5, 'last_error' => 'boom']);

        $response = $this->postJson("/api/dead-jobs/{$id}/retry");

        $response->assertOk();
        $response->assertJsonPath('status', 'pending');
        $response->assertJsonPath('attempts', 0);
        $response->assertJsonPath('last_error', null);
    }

    /**
     * The regression this guards: DeadJobController::retry() used to only
     * update MySQL. Under redis/redis_streams a "revived" job flipped to
     * pending here but nothing pushed it back into the ready list/stream,
     * so no worker was ever signalled — it sat pending forever.
     */
    public function test_retry_makes_the_job_claimable_again_under_redis(): void
    {
        $this->useDriver('redis');
        Redis::connection()->flushdb();

        $id = $this->postJson('/api/jobs', [
            'type' => 'send_email', 'payload' => ['to' => 'a@x.com'], 'idempotency_key' => 'dj-redis-revive',
        ])->json('id');
        Job::query()->whereKey($id)->update(['status' => 'dead', 'attempts' => 5]);

        $this->postJson("/api/dead-jobs/{$id}/retry")->assertOk();

        $claimed = app(JobQueueInterface::class)->claimNextJob('http-test-worker');

        $this->assertNotNull($claimed);
        $this->assertSame($id, $claimed->id);
    }
}
