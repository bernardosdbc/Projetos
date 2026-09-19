<?php

namespace Tests\Feature\Http;

use App\Models\WorkerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_reports_online_and_offline_workers(): void
    {
        WorkerHeartbeat::create([
            'worker_id' => 'worker-fresh',
            'status' => 'idle',
            'current_job_id' => null,
            'last_seen_at' => now(),
        ]);

        WorkerHeartbeat::create([
            'worker_id' => 'worker-stale',
            'status' => 'processing',
            'current_job_id' => 42,
            'last_seen_at' => now()->subSeconds(30),
        ]);

        $response = $this->getJson('/api/workers');

        $response->assertOk();

        $workers = collect($response->json())->keyBy('worker_id');
        $this->assertTrue($workers['worker-fresh']['online']);
        $this->assertFalse($workers['worker-stale']['online']);
        $this->assertSame('processing', $workers['worker-stale']['status']);
        $this->assertSame(42, $workers['worker-stale']['current_job_id']);
    }

    public function test_index_returns_empty_array_when_no_workers_have_reported(): void
    {
        $response = $this->getJson('/api/workers');

        $response->assertOk();
        $response->assertExactJson([]);
    }
}
