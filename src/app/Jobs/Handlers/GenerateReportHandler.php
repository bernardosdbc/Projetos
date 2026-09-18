<?php

namespace App\Jobs\Handlers;

use App\Models\Job;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GenerateReportHandler implements JobHandlerInterface
{
    public function handle(Job $job): void
    {
        DB::table('job_runs')->insert([
            'job_id' => $job->id,
            'reserved_by' => $job->reserved_by,
            'ran_at' => now(),
        ]);

        $failTimes = (int) ($job->payload['fail_times'] ?? 0);
        if ($failTimes > 0 && $job->attempts <= $failTimes) {
            throw new RuntimeException("Controlled failure (attempt {$job->attempts} of {$failTimes})");
        }

        $sleepSeconds = (int) ($job->payload['sleep_seconds'] ?? 0);
        if ($sleepSeconds > 0) {
            sleep($sleepSeconds);
        }

        $report = (string) ($job->payload['report'] ?? 'unknown');
        if ($report === '') {
            throw new RuntimeException('payload.report is required');
        }

        DB::transaction(function () use ($job, $report): void {
            $inserted = DB::table('idempotency_receipts')->insertOrIgnore([
                'idempotency_key' => $job->idempotency_key,
                'job_type' => $job->type,
                'created_at' => now(),
            ]);

            if ($inserted === 0) {
                return;
            }

            // Efeito colateral de estudo: "gera" o relatório (log). Troque por PDF/S3/etc.
            Log::info('generate_report side effect executed', [
                'job_id' => $job->id,
                'report' => $report,
                'period' => $job->payload['period'] ?? null,
            ]);
        });
    }
}
