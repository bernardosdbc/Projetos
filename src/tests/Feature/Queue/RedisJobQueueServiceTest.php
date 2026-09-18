<?php

namespace Tests\Feature\Queue;

use App\Contracts\JobQueueInterface;
use App\Models\Job;
use App\Services\RedisJobQueueService;
use Illuminate\Support\Facades\Redis;

class RedisJobQueueServiceTest extends JobQueueContractTestCase
{
    protected function makeQueue(): JobQueueInterface
    {
        return app(RedisJobQueueService::class);
    }

    protected function makeJobLookStuck(JobQueueInterface $queue, Job $job): int
    {
        Redis::hset('jobs:meta:'.$job->id, 'reserved_at', (string) (time() - 200));

        return 120;
    }
}
