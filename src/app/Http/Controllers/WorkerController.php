<?php

namespace App\Http\Controllers;

use App\Models\WorkerHeartbeat;
use Illuminate\Http\JsonResponse;

class WorkerController extends Controller
{
    public function index(): JsonResponse
    {
        $threshold = now()->subSeconds((int) config('jobs.worker_offline_after', 8));

        $workers = WorkerHeartbeat::query()
            ->orderBy('worker_id')
            ->get()
            ->map(fn (WorkerHeartbeat $worker) => [
                'worker_id' => $worker->worker_id,
                'status' => $worker->status,
                'current_job_id' => $worker->current_job_id,
                'last_seen_at' => $worker->last_seen_at,
                'online' => $worker->last_seen_at->gt($threshold),
            ]);

        return response()->json($workers);
    }
}
