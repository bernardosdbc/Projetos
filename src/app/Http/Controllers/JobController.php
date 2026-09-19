<?php

namespace App\Http\Controllers;

use App\Contracts\JobQueueInterface;
use App\Enums\JobPriority;
use App\Http\Requests\StoreJobRequest;
use App\Models\Job;
use Illuminate\Http\JsonResponse;

class JobController extends Controller
{
    public function index(\Illuminate\Http\Request $request): JsonResponse
    {
        $query = Job::query()->orderByDesc('id');

        $status = $request->string('status')->toString();
        if ($status !== '') {
            $query->where('status', $status);
        }

        return response()->json($query->limit(50)->get());
    }

    public function stats(): JsonResponse
    {
        $counts = Job::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $completed = (int) ($counts['completed'] ?? 0);
        $dead = (int) ($counts['dead'] ?? 0);

        // Janela de 60s: reflete atividade recente, não a vida inteira da fila.
        $jobsLastMinute = Job::query()
            ->where('status', 'completed')
            ->where('completed_at', '>=', now()->subSeconds(60))
            ->count();

        // reserved_at → completed_at: tempo do claim até a conclusão (última
        // tentativa), não da criação — mede o processamento em si, não a
        // espera na fila.
        $avgUs = Job::query()
            ->where('status', 'completed')
            ->where('completed_at', '>=', now()->subMinutes(5))
            ->whereNotNull('reserved_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(MICROSECOND, reserved_at, completed_at)) as avg_us')
            ->value('avg_us');

        return response()->json([
            'pending' => (int) ($counts['pending'] ?? 0),
            'processing' => (int) ($counts['processing'] ?? 0),
            'completed' => $completed,
            'dead' => $dead,
            'jobs_per_second' => round($jobsLastMinute / 60, 2),
            'avg_processing_ms' => $avgUs !== null ? round(((float) $avgUs) / 1000, 1) : null,
            'failure_rate' => ($completed + $dead) > 0 ? round($dead / ($completed + $dead), 4) : 0.0,
        ]);
    }

    public function store(StoreJobRequest $request, JobQueueInterface $queue): JsonResponse
    {
        $priority = $request->filled('priority')
            ? JobPriority::from($request->string('priority')->toString())
            : JobPriority::Normal;

        $executeAt = $request->filled('execute_at')
            ? \Illuminate\Support\Carbon::parse($request->string('execute_at')->toString())
            : null;

        $job = $queue->enqueue(
            $request->string('type')->toString(),
            $request->array('payload'),
            $request->string('idempotency_key')->toString(),
            $priority,
            $executeAt,
        );

        return response()->json($job, 202);
    }

    public function show(Job $job): JsonResponse
    {
        return response()->json($job);
    }
}
