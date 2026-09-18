<?php

namespace App\Services;

class BackoffCalculator
{
    public static function secondsFor(int $attempt): int
    {
        $base = max(1, (int) config('jobs.backoff_base', 30));

        return $base * (2 ** max(0, $attempt - 1));
    }
}