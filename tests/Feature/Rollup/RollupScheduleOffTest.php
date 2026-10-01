<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Rollup;

use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;

class RollupScheduleOffTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('spa-analytics.rollups.schedule', false);
    }

    public function test_nothing_is_scheduled_when_the_switch_is_off(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->command)->implode(' | ');

        $this->assertStringNotContainsString('spa-analytics:rollup', $commands);
        $this->assertStringNotContainsString('spa-analytics:prune', $commands);
    }
}
