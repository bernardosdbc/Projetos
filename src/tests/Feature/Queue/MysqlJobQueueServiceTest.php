<?php

namespace Tests\Feature\Queue;

use App\Contracts\JobQueueInterface;
use App\Models\Job;
use App\Services\MysqlJobQueueService;
use Illuminate\Support\Facades\DB;

class MysqlJobQueueServiceTest extends JobQueueContractTestCase
{
    protected function makeQueue(): JobQueueInterface
    {
        return app(MysqlJobQueueService::class);
    }

    protected function makeJobLookStuck(JobQueueInterface $queue, Job $job): int
    {
        DB::table('jobs')->where('id', $job->id)->update([
            'reserved_at' => now()->subSeconds(200),
        ]);

        return 120;
    }
}
