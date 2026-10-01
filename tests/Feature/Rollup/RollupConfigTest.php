<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Rollup;

use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;

class RollupConfigTest extends TestCase
{
    public function test_rollup_defaults_are_present(): void
    {
        $this->assertTrue(config('spa-analytics.rollups.schedule'));
        $this->assertSame(3, config('spa-analytics.rollups.lookback_hours'));
    }
}
