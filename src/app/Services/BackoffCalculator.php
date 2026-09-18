<?php

namespace App\Services;

class BackoffCalculator
{
    public static function secondsFor(int $attempt): int
    {
        return 30 * (2 ** max(0, $attempt - 1));
    }
}