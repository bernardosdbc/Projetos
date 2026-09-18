<?php

namespace App\Jobs\Handlers;

use App\Models\Job;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Generic HTTP health-check handler: GETs each target and fails the job if
 * any of them doesn't answer 2xx within the timeout. A failure runs through
 * the same retry/backoff/DLQ machinery as any other job type — repeated
 * failures land it in `dead`, which GET /dead-jobs already surfaces as an
 * "something's down" signal, with no bespoke alerting needed.
 *
 * No `targets` in payload -> checks this app's own /api/health, so the
 * handler is testable without depending on any external stack.
 */
class SmokeCheckHandler implements JobHandlerInterface
{
    public function handle(Job $job): void
    {
        DB::table('job_runs')->insert([
            'job_id' => $job->id,
            'reserved_by' => $job->reserved_by,
            'ran_at' => now(),
        ]);

        $timeoutSeconds = (int) ($job->payload['timeout_seconds'] ?? 5);
        $results = [];
        $failures = [];

        foreach ($this->targets($job) as $target) {
            $startedAt = microtime(true);

            try {
                $response = Http::timeout($timeoutSeconds)->get($target);
                $ok = $response->successful();
                $status = $response->status();
            } catch (Throwable $e) {
                $ok = false;
                $status = null;
            }

            $ms = (int) round((microtime(true) - $startedAt) * 1000);
            $results[] = ['target' => $target, 'ok' => $ok, 'status' => $status, 'ms' => $ms];

            if (! $ok) {
                $failures[] = "{$target} (status=".($status ?? 'n/a').")";
            }
        }

        Log::info('smoke_check results', ['job_id' => $job->id, 'results' => $results]);

        if ($failures !== []) {
            throw new RuntimeException('Smoke check failed: '.implode(', ', $failures));
        }
    }

    /**
     * @return list<string>
     */
    private function targets(Job $job): array
    {
        $raw = $job->payload['targets'] ?? null;

        if (is_array($raw) && $raw !== []) {
            return array_values(array_filter(array_map(
                static fn (mixed $target): string => trim((string) $target),
                $raw,
            )));
        }

        // Reachable from worker containers via the docker-compose service name.
        return ['http://app:8000/api/health'];
    }
}
