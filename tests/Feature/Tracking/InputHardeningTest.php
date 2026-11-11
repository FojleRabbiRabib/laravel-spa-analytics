<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ReferrerType;
use FojleRabbiRabib\LaravelSpaAnalytics\Facades\Analytics;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

class InputHardeningTest extends TestCase
{
    private const VISITOR = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 12:00:00');
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('spa-analytics.tracking.write_mode', 'sync');
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->get('/page', fn () => response('<html></html>', 200, ['Content-Type' => 'text/html']));
        $router->middleware('web')->get('/track', function () {
            Analytics::track('ping');

            return 'ok';
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @param  array<string, string>  $headers
     */
    private function collect(array $events, array $headers = []): TestResponse
    {
        return $this->withCredentials()->withCookie('spa_analytics_vid', self::VISITOR)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0', ...$headers])
            ->postJson(route('spa-analytics.collect'), ['events' => $events]);
    }

    private function visit(string $uri = '/page', array $headers = []): TestResponse
    {
        return $this->withHeaders(['Accept' => 'text/html', ...$headers])->get($uri);
    }

    public function test_a_referer_host_longer_than_the_column_is_not_stored_and_the_page_view_still_is(): void
    {
        $this->visit(headers: ['Referer' => 'https://'.str_repeat('a', 300).'.example/'])->assertOk();

        $event = AnalyticsEvent::query()->sole();
        $this->assertNull($event->referrer_host);
        $this->assertSame(ReferrerType::Direct, $event->referrer_type);
        $this->assertNull(AnalyticsSession::query()->sole()->referrer_host);
    }

    public function test_a_collect_referrer_host_longer_than_the_column_is_not_stored(): void
    {
        $this->collect([['kind' => 'pageview', 'path' => '/docs', 'referrer' => 'https://'.str_repeat('a', 300).'.example/']])->assertNoContent();

        $this->assertNull(AnalyticsEvent::query()->sole()->referrer_host);
    }

    public function test_a_referrer_host_with_markup_is_not_stored(): void
    {
        $this->collect([['kind' => 'pageview', 'path' => '/docs', 'referrer' => 'http://<script>alert(1)</script>/x']])->assertNoContent();

        $this->assertNull(AnalyticsEvent::query()->sole()->referrer_host);
    }

    public function test_a_valid_referrer_host_is_kept_in_lower_case(): void
    {
        $this->collect([['kind' => 'pageview', 'path' => '/docs', 'referrer' => 'https://Bücher.Example/path']])->assertNoContent();

        $event = AnalyticsEvent::query()->sole();
        $this->assertSame('bücher.example', $event->referrer_host);
        $this->assertSame(ReferrerType::Referral, $event->referrer_type);
    }

    public function test_an_outbound_host_with_markup_is_not_stored(): void
    {
        $this->collect([['kind' => 'outbound', 'path' => '/page', 'url' => 'http://<img src=x onerror=1>.example/x']])->assertNoContent();

        $this->assertSame(0, AnalyticsEvent::query()->count());
    }

    public function test_control_characters_are_removed_from_a_collect_path_and_from_properties(): void
    {
        $this->collect([
            ['kind' => 'pageview', 'path' => "/a\u{0000}b\u{0007}c"],
            ['kind' => 'event', 'path' => '/page', 'name' => 'signup', 'properties' => ["k\u{0000}ey" => "va\u{0000}lue\u{0007}"]],
        ])->assertNoContent();

        $this->assertSame(['/abc', '/page'], AnalyticsEvent::query()->orderBy('id')->pluck('path')->all());
        $this->assertSame(['key' => 'value'], AnalyticsEvent::query()->where('name', 'signup')->sole()->properties);
    }

    public function test_invalid_utf8_in_the_user_agent_and_utm_values_is_stored_scrubbed(): void
    {
        $this->visit('/page?utm_source=news%ff%fe&utm_campaign=sp%00ring', ['User-Agent' => "Mozilla/5.0 \xff\xfe bot-free"])->assertOk();

        $event = AnalyticsEvent::query()->sole();
        $this->assertTrue(mb_check_encoding((string) $event->user_agent, 'UTF-8'));
        $this->assertTrue(mb_check_encoding((string) $event->utm_source, 'UTF-8'));
        $this->assertSame('spring', $event->utm_campaign);
        $this->assertSame($event->utm_source, AnalyticsSession::query()->sole()->utm_source);
    }

    public function test_a_language_that_is_not_a_language_tag_is_not_stored(): void
    {
        foreach (['<svg onload=x>', '*', 'en US', '"><b>'] as $i => $language) {
            $this->visit('/page?i='.$i, ['Accept-Language' => $language])->assertOk();
        }

        $this->assertSame([null, null, null, null], AnalyticsEvent::query()->orderBy('id')->pluck('language')->all());
    }

    public function test_a_real_language_tag_is_kept_and_a_long_one_is_cut_at_a_subtag(): void
    {
        foreach (['bn-BD,bn;q=0.9', 'zh-Hant-TW-x-private-use-long', 'es-419', 'sr-Latn-RS', 'en_US'] as $i => $language) {
            $this->visit('/page?i='.$i, ['Accept-Language' => $language])->assertOk();
        }

        $this->assertSame(['bn-BD', 'zh-Hant-TW', 'es-419', 'sr-Latn-RS', 'en-US'], AnalyticsEvent::query()->orderBy('id')->pluck('language')->all());
    }

    public function test_a_referrer_with_an_ipv6_host_and_port_and_a_bengali_host_are_kept(): void
    {
        $this->collect([
            ['kind' => 'pageview', 'path' => '/a', 'referrer' => 'http://[2001:db8::1]:8080/x'],
            ['kind' => 'pageview', 'path' => '/b', 'referrer' => 'https://বাংলা.com/x'],
        ])->assertNoContent();

        $this->assertSame(['[2001:db8::1]', 'বাংলা.com'], AnalyticsEvent::query()->orderBy('id')->pluck('referrer_host')->all());
    }

    public function test_a_server_side_custom_event_stores_a_user_agent_with_nul_and_invalid_bytes_scrubbed(): void
    {
        $this->withHeaders(['User-Agent' => "Mozilla/5.0 \0\xff end"])->get('/track')->assertOk();

        $event = AnalyticsEvent::query()->where('name', 'ping')->sole();
        $this->assertTrue(mb_check_encoding((string) $event->user_agent, 'UTF-8'));
        $this->assertStringNotContainsString("\0", (string) $event->user_agent);
    }

    public function test_a_collect_body_over_64_kb_is_refused_before_anything_is_stored(): void
    {
        $this->collect([['kind' => 'event', 'path' => '/page', 'name' => 'big', 'properties' => ['k' => str_repeat('x', 70000)]]])
            ->assertStatus(413);

        $this->assertSame(0, AnalyticsEvent::query()->count());
    }

    public function test_a_normal_collect_batch_is_still_accepted(): void
    {
        $events = array_fill(0, 20, ['kind' => 'event', 'path' => '/page', 'name' => 'ping', 'properties' => ['k' => str_repeat('x', 255)]]);

        $this->collect($events)->assertNoContent();

        $this->assertSame(20, AnalyticsEvent::query()->count());
    }
}
