<?php

namespace App\Http\Controllers;

use App\Contracts\JobQueueInterface;
use App\Models\Job;
use Illuminate\Http\JsonResponse;

class DeadJobController extends Controller
{
    public function index(): JsonResponse
    {
        $jobs = Job::query()
            ->where('status', 'dead')
            ->orderByDesc('id')
            ->get();

        return response()->json($jobs);
    }

    public function retry(Job $job, JobQueueInterface $queue): JsonResponse
    {
        if ($job->status !== 'dead') {
            return response()->json(['message' => 'Job is not dead.'], 409);
        }

        $job->update([
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now(),
            'reserved_at' => null,
            'reserved_by' => null,
            'last_error' => null,
            'completed_at' => null,
        ]);

        // Redis/Streams drivers keep ready/delayed state outside MySQL — without
        // this the row flips to pending here but no worker is ever signalled to
        // claim it (pre-existing gap, surfaced while wiring priority requeueing).
        $queue->requeue($job->fresh());

        return response()->json($job->fresh());
    }
}
