<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Identity;

use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

class IdentityRateLimitTest extends TestCase
{
    private const VISITOR_A = '11111111-1111-4111-8111-111111111111';

    private const VISITOR_B = '22222222-2222-4222-8222-222222222222';

    private function limits(int $perVisitor, int $perIp): void
    {
        config()->set('spa-analytics.identity.rate_limit_per_minute', $perVisitor);
        config()->set('spa-analytics.identity.rate_limit_per_ip_per_minute', $perIp);
    }

    private function handshakeAs(?string $visitor): TestResponse
    {
        $this->defaultCookies = [];

        if ($visitor !== null) {
            $this->withCookie('spa_analytics_vid', $visitor);
        }

        return $this->withCredentials()->postJson(route('spa-analytics.identity.handshake'));
    }

    public function test_one_visitor_is_limited_to_its_own_allowance(): void
    {
        $this->limits(2, 100);

        $this->handshakeAs(self::VISITOR_A)->assertOk();
        $this->handshakeAs(self::VISITOR_A)->assertOk();
        $this->handshakeAs(self::VISITOR_A)->assertStatus(429);
    }

    public function test_visitors_sharing_an_address_do_not_share_an_allowance(): void
    {
        $this->limits(2, 100);

        $this->handshakeAs(self::VISITOR_A)->assertOk();
        $this->handshakeAs(self::VISITOR_A)->assertOk();
        $this->handshakeAs(self::VISITOR_A)->assertStatus(429);

        $this->handshakeAs(self::VISITOR_B)->assertOk();
        $this->handshakeAs(self::VISITOR_B)->assertOk();
    }

    public function test_the_address_ceiling_stops_a_client_that_rotates_cookies(): void
    {
        $this->limits(100, 3);

        $this->handshakeAs(self::VISITOR_A)->assertOk();
        $this->handshakeAs(self::VISITOR_B)->assertOk();
        $this->handshakeAs('33333333-3333-4333-8333-333333333333')->assertOk();
        $this->handshakeAs('44444444-4444-4444-8444-444444444444')->assertStatus(429);
    }

    public function test_the_address_ceiling_stops_callers_without_a_cookie(): void
    {
        $this->limits(1, 3);

        $this->handshakeAs(null)->assertOk();
        $this->handshakeAs(null)->assertOk();
        $this->handshakeAs(null)->assertOk();
        $this->handshakeAs(null)->assertStatus(429);
    }

    public function test_limiter_keys_never_contain_the_raw_address_or_visitor_id(): void
    {
        $request = Request::create('/spa-analytics/handshake', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']);
        $request->cookies->set('spa_analytics_vid', self::VISITOR_A);

        $limits = RateLimiter::limiter('spa-analytics-identity')($request);

        $this->assertCount(2, $limits);

        foreach ($limits as $limit) {
            $this->assertStringNotContainsString('203.0.113.7', (string) $limit->key);
            $this->assertStringNotContainsString(self::VISITOR_A, (string) $limit->key);
        }

        $this->assertNotSame($limits[0]->key, $limits[1]->key);
    }

    public function test_a_caller_without_a_valid_cookie_gets_only_the_address_ceiling(): void
    {
        $request = Request::create('/spa-analytics/handshake', 'POST');
        $request->cookies->set('spa_analytics_vid', 'not-a-uuid');

        $this->assertCount(1, RateLimiter::limiter('spa-analytics-identity')($request));
    }
}
