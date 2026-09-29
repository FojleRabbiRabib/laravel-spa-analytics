<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\WriteMode;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;

class TrackingConfigTest extends TestCase
{
    public function test_tracking_defaults_are_present(): void
    {
        $this->assertTrue(config('laravel-spa-analytics.tracking.register_middleware'));
        $this->assertSame('defer', config('laravel-spa-analytics.tracking.write_mode'));
        $this->assertNull(config('laravel-spa-analytics.tracking.connection'));
        $this->assertNull(config('laravel-spa-analytics.tracking.queue'));
        $this->assertSame(['up', 'spa-analytics/*', 'reset-password/*', 'password/reset/*'], config('laravel-spa-analytics.tracking.excluded_paths'));
        $this->assertContains('bot', config('laravel-spa-analytics.tracking.bot_patterns'));
        $this->assertContains('google.', config('laravel-spa-analytics.tracking.search_hosts'));
        $this->assertContains('facebook.', config('laravel-spa-analytics.tracking.social_hosts'));
    }

    public function test_default_write_mode_is_a_valid_case(): void
    {
        $this->assertNotNull(WriteMode::tryFrom((string) config('laravel-spa-analytics.tracking.write_mode')));
    }
}
