<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\WriteMode;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;

class TrackingConfigTest extends TestCase
{
    public function test_tracking_defaults_are_present(): void
    {
        $this->assertTrue(config('spa-analytics.tracking.register_middleware'));
        $this->assertSame('defer', config('spa-analytics.tracking.write_mode'));
        $this->assertNull(config('spa-analytics.tracking.connection'));
        $this->assertNull(config('spa-analytics.tracking.queue'));
        $this->assertSame(['up', 'spa-analytics/*', 'reset-password/*', 'password/reset/*'], config('spa-analytics.tracking.excluded_paths'));
        $this->assertContains('bot', config('spa-analytics.tracking.bot_patterns'));
        $this->assertContains('google.', config('spa-analytics.tracking.search_hosts'));
        $this->assertContains('facebook.', config('spa-analytics.tracking.social_hosts'));
    }

    public function test_default_write_mode_is_a_valid_case(): void
    {
        $this->assertNotNull(WriteMode::tryFrom((string) config('spa-analytics.tracking.write_mode')));
    }
}
