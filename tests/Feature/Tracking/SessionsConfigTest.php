<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;

class SessionsConfigTest extends TestCase
{
    public function test_session_timeout_defaults_to_thirty_minutes(): void
    {
        $this->assertSame(30, config('laravel-spa-analytics.sessions.timeout_minutes'));
    }

    public function test_relink_is_opt_in(): void
    {
        $this->assertFalse(config('laravel-spa-analytics.identity.relink'));
    }
}
