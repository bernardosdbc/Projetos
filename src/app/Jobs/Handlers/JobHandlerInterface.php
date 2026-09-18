<?php

namespace App\Jobs\Handlers;

use App\Models\Job;

interface JobHandlerInterface
{
    public function handle(Job $job): void;
}