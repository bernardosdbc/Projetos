<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreJobRequest;
use App\Models\Job;
use App\Services\JobQueueService;
use Illuminate\Http\JsonResponse;

class JobController extends Controller
{
    public function store(StoreJobRequest $request, JobQueueService $queue): JsonResponse
    {
        $job = $queue->enqueue($request->string('type')->toString(), $request->array('payload'), $request->string('idempotency_key')->toString());
        return response()->json($job, 202);
    }

    public function show(Job $job): JsonResponse
    {
        return response()->json($job);
    }
}