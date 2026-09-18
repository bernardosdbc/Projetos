<?php

namespace App\Providers;

use App\Contracts\JobQueueInterface;
use App\Services\MysqlJobQueueService;
use App\Services\RedisJobQueueService;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(JobQueueInterface::class, function ($app) {
            return match (config('jobs.driver')) {
                'redis' => $app->make(RedisJobQueueService::class),
                'mysql' => $app->make(MysqlJobQueueService::class),
                default => throw new InvalidArgumentException(
                    'Unsupported QUEUE_DRIVER ['.config('jobs.driver').']. Use mysql or redis.'
                ),
            };
        });
    }

    public function boot(): void
    {
        //
    }
}
