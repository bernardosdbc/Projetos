<?php

namespace App\Jobs\Handlers;

use App\Models\Job;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SendEmailHandler implements JobHandlerInterface
{
    public function handle(Job $job): void
    {
        // Every invocation is recorded — duplicate rows mean two workers ran the same job.
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

        DB::transaction(function () use ($job): void {
            $inserted = DB::table('idempotency_receipts')->insertOrIgnore([
                'idempotency_key' => $job->idempotency_key,
                'job_type' => $job->type,
                'created_at' => now(),
            ]);

            if ($inserted === 0) {
                return;
            }

            Log::info('send_email side effect executed', [
                'job_id' => $job->id,
                'to' => $job->payload['to'] ?? null,
            ]);
        });
    }
}
