<?php

namespace Tests\Feature\Queue;

use App\Contracts\JobQueueInterface;
use App\Models\Job;
use App\Services\RedisStreamsJobQueueService;

class RedisStreamsJobQueueServiceTest extends JobQueueContractTestCase
{
    protected function makeQueue(): JobQueueInterface
    {
        return app(RedisStreamsJobQueueService::class);
    }

    protected function makeJobLookStuck(JobQueueInterface $queue, Job $job): int
    {
        // Streams track idle time internally (PEL), nothing to rewrite —
        // a short real wait is the only way to make XAUTOCLAIM see it as stuck.
        sleep(2);

        return 1;
    }
}
