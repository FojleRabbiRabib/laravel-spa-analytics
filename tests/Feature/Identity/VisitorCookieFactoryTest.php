<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Identity;

use FojleRabbiRabib\LaravelSpaAnalytics\Services\Identity\VisitorCookieFactory;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;

class VisitorCookieFactoryTest extends TestCase
{
    public function test_cookie_carries_the_configured_name_lifetime_and_attributes(): void
    {
        config()->set('spa-analytics.identity.cookie_name', 'custom_vid');
        config()->set('spa-analytics.identity.cookie_lifetime_days', 10);

        $cookie = app(VisitorCookieFactory::class)->make('abc', true);

        $this->assertSame('custom_vid', $cookie->getName());
        $this->assertSame('abc', $cookie->getValue());
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame('/', $cookie->getPath());
        $this->assertEqualsWithDelta(now()->addDays(10)->timestamp, $cookie->getExpiresTime(), 10);
    }

    public function test_secure_flag_follows_the_argument(): void
    {
        $this->assertFalse(app(VisitorCookieFactory::class)->make('abc', false)->isSecure());
    }
}
