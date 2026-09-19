<?php

namespace Tests\Feature\Http;

use App\Models\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_a_job_with_defaults(): void
    {
        $response = $this->postJson('/api/jobs', [
            'type' => 'send_email',
            'payload' => ['to' => 'a@example.com'],
            'idempotency_key' => 'http-store-1',
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('type', 'send_email');
        $response->assertJsonPath('priority', 'normal');
        $response->assertJsonPath('status', 'pending');
        $response->assertJsonPath('attempts', 0);
        $response->assertJsonPath('max_attempts', 5);
    }

    public function test_store_requires_type_payload_and_idempotency_key(): void
    {
        $response = $this->postJson('/api/jobs', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['type', 'payload', 'idempotency_key']);
    }

    public function test_store_accepts_a_valid_priority(): void
    {
        $response = $this->postJson('/api/jobs', [
            'type' => 'send_email',
            'payload' => ['to' => 'a@example.com'],
            'idempotency_key' => 'http-priority-1',
            'priority' => 'critical',
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('priority', 'critical');
    }

    public function test_store_rejects_an_invalid_priority(): void
    {
        $response = $this->postJson('/api/jobs', [
            'type' => 'send_email',
            'payload' => ['to' => 'a@example.com'],
            'idempotency_key' => 'http-priority-bad',
            'priority' => 'urgent',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['priority']);
    }

    public function test_store_seeds_available_at_from_execute_at(): void
    {
        $executeAt = now()->addMinutes(10);

        $response = $this->postJson('/api/jobs', [
            'type' => 'send_email',
            'payload' => ['to' => 'a@example.com'],
            'idempotency_key' => 'http-execute-at-1',
            'execute_at' => $executeAt->toIso8601String(),
        ]);

        $response->assertStatus(202);

        $job = Job::query()->findOrFail($response->json('id'));
        $this->assertEqualsWithDelta(0, abs($job->available_at->diffInSeconds($executeAt)), 2);
    }

    public function test_store_rejects_a_malformed_execute_at(): void
    {
        $response = $this->postJson('/api/jobs', [
            'type' => 'send_email',
            'payload' => ['to' => 'a@example.com'],
            'idempotency_key' => 'http-execute-at-bad',
            'execute_at' => 'not-a-date',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['execute_at']);
    }

    public function test_store_is_idempotent_by_key(): void
    {
        $payload = [
            'type' => 'send_email',
            'payload' => ['to' => 'a@example.com'],
            'idempotency_key' => 'http-idem-1',
        ];

        $first = $this->postJson('/api/jobs', $payload);
        $second = $this->postJson('/api/jobs', $payload);

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, Job::query()->where('idempotency_key', 'http-idem-1')->count());
    }

    public function test_index_filters_by_status(): void
    {
        $pendingId = $this->postJson('/api/jobs', [
            'type' => 'send_email', 'payload' => ['to' => 'a@x.com'], 'idempotency_key' => 'idx-pending',
        ])->json('id');

        $deadId = $this->postJson('/api/jobs', [
            'type' => 'send_email', 'payload' => ['to' => 'b@x.com'], 'idempotency_key' => 'idx-dead',
        ])->json('id');
        Job::query()->whereKey($deadId)->update(['status' => 'dead']);

        $response = $this->getJson('/api/jobs?status=dead');

        $response->assertOk();
        $ids = collect($response->json())->pluck('id');
        $this->assertTrue($ids->contains($deadId));
        $this->assertFalse($ids->contains($pendingId));
    }

    public function test_stats_counts_by_status(): void
    {
        $this->postJson('/api/jobs', [
            'type' => 'send_email', 'payload' => ['to' => 'a@x.com'], 'idempotency_key' => 'stats-a',
        ]);
        $completedId = $this->postJson('/api/jobs', [
            'type' => 'send_email', 'payload' => ['to' => 'b@x.com'], 'idempotency_key' => 'stats-b',
        ])->json('id');
        Job::query()->whereKey($completedId)->update(['status' => 'completed']);

        $response = $this->getJson('/api/jobs/stats');

        $response->assertOk();
        $response->assertJsonPath('pending', 1);
        $response->assertJsonPath('completed', 1);
        $response->assertJsonPath('processing', 0);
        $response->assertJsonPath('dead', 0);
    }

    public function test_stats_includes_throughput_latency_and_failure_rate(): void
    {
        $now = now();

        Job::create([
            'type' => 'send_email',
            'payload' => ['to' => 'a@x.com'],
            'idempotency_key' => 'metrics-completed-1',
            'status' => 'completed',
            'available_at' => $now,
            'reserved_at' => $now->copy()->subSeconds(2),
            'completed_at' => $now,
        ]);

        Job::create([
            'type' => 'send_email',
            'payload' => ['to' => 'b@x.com'],
            'idempotency_key' => 'metrics-dead-1',
            'status' => 'dead',
            'available_at' => $now,
        ]);

        $response = $this->getJson('/api/jobs/stats');

        $response->assertOk();
        $response->assertJsonPath('jobs_per_second', round(1 / 60, 2));
        $response->assertJsonPath('avg_processing_ms', 2000.0);
        $response->assertJsonPath('failure_rate', 0.5);
    }

    public function test_show_returns_the_job(): void
    {
        $id = $this->postJson('/api/jobs', [
            'type' => 'send_email', 'payload' => ['to' => 'a@x.com'], 'idempotency_key' => 'show-1',
        ])->json('id');

        $response = $this->getJson("/api/jobs/{$id}");

        $response->assertOk();
        $response->assertJsonPath('id', $id);
    }

    public function test_show_returns_404_for_an_unknown_job(): void
    {
        $response = $this->getJson('/api/jobs/999999');

        $response->assertStatus(404);
    }
}
