<?php

namespace App\Http\Controllers;

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

    public function retry(Job $job): JsonResponse
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

        return response()->json($job->fresh());
    }
}
