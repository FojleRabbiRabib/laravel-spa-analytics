<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Identity;

use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;

class IdentityConfigTest extends TestCase
{
    public function test_identity_defaults_are_present(): void
    {
        $this->assertSame('spa_analytics_vid', config('laravel-spa-analytics.identity.cookie_name'));
        $this->assertSame(365, config('laravel-spa-analytics.identity.cookie_lifetime_days'));
        $this->assertNull(config('laravel-spa-analytics.identity.tls_fingerprint_header'));
        $this->assertTrue(config('laravel-spa-analytics.identity.register_middleware'));
        $this->assertSame(60, config('laravel-spa-analytics.identity.nonce_ttl_seconds'));
        $this->assertSame('spa-analytics', config('laravel-spa-analytics.identity.route_prefix'));
        $this->assertSame(30, config('laravel-spa-analytics.identity.rate_limit_per_minute'));
    }
}
