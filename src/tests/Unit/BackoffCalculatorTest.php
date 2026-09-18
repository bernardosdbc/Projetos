<?php

namespace Tests\Unit;

use App\Services\BackoffCalculator;
use Tests\TestCase;

class BackoffCalculatorTest extends TestCase
{
    public function test_doubles_each_attempt_from_the_configured_base(): void
    {
        config(['jobs.backoff_base' => 30]);

        $this->assertSame(30, BackoffCalculator::secondsFor(1));
        $this->assertSame(60, BackoffCalculator::secondsFor(2));
        $this->assertSame(120, BackoffCalculator::secondsFor(3));
        $this->assertSame(240, BackoffCalculator::secondsFor(4));
    }

    public function test_base_is_clamped_to_at_least_one_second(): void
    {
        config(['jobs.backoff_base' => 0]);

        $this->assertSame(1, BackoffCalculator::secondsFor(1));
    }
}
