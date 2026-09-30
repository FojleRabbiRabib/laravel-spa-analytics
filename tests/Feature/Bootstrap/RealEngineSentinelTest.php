<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Bootstrap;

use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * Tripwire: when the CI variables ask for a real engine, the app must actually be running on it. Without this a
 * lost environment would silently turn the database jobs into plain sqlite runs.
 */
class RealEngineSentinelTest extends TestCase
{
    public function test_the_app_runs_on_the_engine_the_environment_asks_for(): void
    {
        if (getenv('SPA_ANALYTICS_TEST_CI') === '1') {
            $this->assertSame((string) getenv('SPA_ANALYTICS_TEST_DB'), DB::connection()->getDriverName());
            $this->assertSame((string) getenv('SPA_ANALYTICS_TEST_CACHE'), config('cache.default'));

            return;
        }

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame('array', config('cache.default'));
    }
}
