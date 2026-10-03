<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ReferrerType;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

class CollectEndpointTest extends TestCase
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
        $router->middleware('web')->get('/pricing', fn () => response('<html></html>', 200, ['Content-Type' => 'text/html']));
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @param  array<string, string>  $headers
     */
    private function collect(array $events, array $headers = []): TestResponse
    {
        return $this->withCredentials()->withCookie('spa_analytics_vid', self::VISITOR)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0', 'Accept-Language' => 'bn-BD,bn;q=0.9', ...$headers])
            ->postJson(route('spa-analytics.collect'), ['events' => $events]);
    }

    public function test_a_client_page_view_is_stored_with_the_visitor_audience_and_a_session(): void
    {
        $this->collect([['kind' => 'pageview', 'path' => '/docs', 'referrer' => 'https://www.google.com/search?q=x']])->assertNoContent();

        $event = AnalyticsEvent::query()->sole();

        $this->assertSame(EventType::PageView, $event->type);
        $this->assertSame('/docs', $event->path);
        $this->assertNull($event->status);
        $this->assertSame(ReferrerType::Search, $event->referrer_type);
        $this->assertSame('bn-BD', $event->language);
        $this->assertNotNull($event->session_id);
        $this->assertSame('/docs', AnalyticsSession::query()->sole()->entry_path);
        $this->assertSame('firefox', strtolower((string) AnalyticsSession::query()->sole()->browser));
    }

    public function test_an_outbound_click_keeps_the_host_and_path_but_never_the_query(): void
    {
        $this->collect([['kind' => 'outbound', 'path' => '/pricing', 'url' => 'https://Example.ORG/docs/start?token=secret#frag']])->assertNoContent();

        $event = AnalyticsEvent::query()->sole();

        $this->assertSame(EventType::OutboundClick, $event->type);
        $this->assertSame('example.org', $event->target_host);
        $this->assertSame('/docs/start', $event->target_path);
        $this->assertSame('/pricing', $event->path);
    }

    public function test_same_site_and_non_http_links_are_not_outbound_clicks(): void
    {
        $this->collect([
            ['kind' => 'outbound', 'path' => '/pricing', 'url' => 'http://localhost/about'],
            ['kind' => 'outbound', 'path' => '/pricing', 'url' => 'mailto:me@example.org'],
            ['kind' => 'outbound', 'path' => '/pricing', 'url' => 'javascript:alert(1)'],
        ])->assertNoContent();

        $this->assertSame(0, AnalyticsEvent::query()->count());
    }

    public function test_only_the_four_scroll_milestones_are_recorded(): void
    {
        $this->collect(array_map(fn (int $percent): array => ['kind' => 'scroll', 'path' => '/pricing', 'percent' => $percent], [0, 10, 25, 50, 75, 100]))->assertNoContent();

        $this->assertSame([25, 50, 75, 100], AnalyticsEvent::query()->orderBy('id')->pluck('scroll_percent')->all());
    }

    public function test_events_and_goals_follow_the_same_rules_as_the_server_side_facade(): void
    {
        $this->collect([
            ['kind' => 'event', 'path' => '/pricing', 'name' => 'clicked_cta', 'properties' => ['plan' => 'pro', 'nested' => ['x' => 1]], 'value' => 5],
            ['kind' => 'goal', 'path' => '/pricing', 'name' => 'signup', 'value' => 19.999],
            ['kind' => 'goal', 'path' => '/pricing', 'name' => 'bad name!'],
            ['kind' => 'goal', 'path' => '/pricing', 'name' => 'negative', 'value' => -4],
        ])->assertNoContent();

        $events = AnalyticsEvent::query()->orderBy('id')->get();

        $this->assertSame(['clicked_cta', 'signup', 'negative'], $events->pluck('name')->all());
        $this->assertSame(['plan' => 'pro'], $events[0]->properties);
        $this->assertNull($events[0]->value);
        $this->assertSame('20.00', $events[1]->value);
        $this->assertNull($events[2]->value);
        $this->assertSame(EventType::Goal, $events[1]->type);
    }

    public function test_excluded_paths_are_dropped_for_every_kind_and_target_paths_are_not_stored(): void
    {
        $this->collect([
            ['kind' => 'pageview', 'path' => '/reset-password/abc123'],
            ['kind' => 'event', 'path' => '/password/reset/abc123', 'name' => 'x'],
            ['kind' => 'scroll', 'path' => '/reset-password/abc123', 'percent' => 25],
            ['kind' => 'outbound', 'path' => '/reset-password/abc123', 'url' => 'https://example.org/'],
            ['kind' => 'outbound', 'path' => '/pricing', 'url' => 'https://example.org/reset-password/abc123'],
        ])->assertNoContent();

        $event = AnalyticsEvent::query()->sole();

        $this->assertSame('example.org', $event->target_host);
        $this->assertNull($event->target_path);
    }

    public function test_the_time_is_the_request_time_minus_the_reported_age_and_the_batch_keeps_its_order(): void
    {
        $this->collect([
            ['kind' => 'goal', 'path' => '/a', 'name' => 'first', 'age' => 4000],
            ['kind' => 'pageview', 'path' => '/b', 'age' => 1000],
            ['kind' => 'event', 'path' => '/b', 'name' => 'late', 'age' => 99999999],
            ['kind' => 'event', 'path' => '/b', 'name' => 'future', 'age' => 0],
        ])->assertNoContent();

        $events = AnalyticsEvent::query()->orderBy('id')->get();

        $this->assertSame(['2026-03-10 11:59:56', '2026-03-10 11:59:59', '2026-03-10 11:55:00', '2026-03-10 12:00:00'], $events->map(fn ($event) => $event->occurred_at->toDateTimeString())->all());
    }

    public function test_a_page_view_the_server_just_recorded_is_not_recorded_again(): void
    {
        $this->withCredentials()->withCookie('spa_analytics_vid', self::VISITOR)
            ->withHeaders(['Accept' => 'text/html', 'User-Agent' => 'Mozilla/5.0 (X11; Linux) Firefox/121.0'])
            ->get('/pricing')->assertOk();

        $this->collect([['kind' => 'pageview', 'path' => '/pricing'], ['kind' => 'pageview', 'path' => '/docs'], ['kind' => 'pageview', 'path' => '/docs']])->assertNoContent();

        $this->assertSame(['/pricing', '/docs'], AnalyticsEvent::query()->orderBy('id')->pluck('path')->all());
    }

    public function test_bot_user_agents_are_flagged(): void
    {
        $this->collect([['kind' => 'pageview', 'path' => '/docs']], ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)'])->assertNoContent();

        $this->assertTrue(AnalyticsEvent::query()->sole()->is_bot);
    }

    public function test_a_session_never_moves_backwards_for_an_older_event(): void
    {
        $this->collect([['kind' => 'pageview', 'path' => '/later']])->assertNoContent();
        $this->collect([['kind' => 'pageview', 'path' => '/earlier', 'age' => 60000]])->assertNoContent();

        $session = AnalyticsSession::query()->sole();

        $this->assertSame('/later', $session->exit_path);
        $this->assertSame('/earlier', $session->entry_path);
        $this->assertSame(2, $session->page_views);
    }

    public function test_one_bad_field_drops_that_event_and_never_the_rest_of_the_batch(): void
    {
        $this->collect([
            ['kind' => 'event', 'path' => '/pricing', 'name' => str_repeat('n', 200)],
            ['kind' => 'event', 'path' => '/pricing', 'name' => 'with_bad_properties', 'properties' => 'oops'],
            ['kind' => 'pageview', 'path' => '/'.str_repeat('p', 600), 'referrer' => ['not', 'a', 'string']],
            ['kind' => 'outbound', 'path' => '/pricing', 'url' => ['x']],
            ['kind' => 'scroll', 'path' => '/pricing', 'percent' => 'fifty'],
            ['kind' => 'goal', 'path' => '/pricing', 'name' => 'signup', 'value' => 'lots', 'age' => 'soon'],
        ])->assertNoContent();

        $events = AnalyticsEvent::query()->orderBy('id')->get();

        $this->assertSame(['with_bad_properties', null, 'signup'], $events->pluck('name')->all());
        $this->assertNull($events[0]->properties);
        $this->assertSame(512, mb_strlen((string) $events[1]->path));
        $this->assertNull($events[2]->value);
    }

    public function test_the_shared_fixture_batch_stores_one_event_of_each_kind(): void
    {
        $batch = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/collect-batch.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->collect($batch['events'])->assertNoContent();

        $events = AnalyticsEvent::query()->orderBy('id')->get();

        $this->assertSame(
            [EventType::PageView, EventType::OutboundClick, EventType::FileDownload, EventType::ScrollDepth, EventType::Custom, EventType::Goal],
            $events->pluck('type')->all(),
        );
        $this->assertSame('example.org', $events[1]->target_host);
        $this->assertSame('/a', $events[1]->target_path);
        $this->assertSame('/files/guide.pdf', $events[2]->target_path);
        $this->assertSame('pdf', $events[2]->file_extension);
        $this->assertSame(50, $events[3]->scroll_percent);
        $this->assertSame(['plan' => 'pro'], $events[4]->properties);
        $this->assertSame('49.50', $events[5]->value);
        $this->assertSame(['order' => 'A1'], $events[5]->properties);
    }

    public function test_invalid_batches_are_rejected(): void
    {
        $this->collect([])->assertUnprocessable();
        $this->collect([['kind' => 'nope', 'path' => '/a']])->assertUnprocessable();
        $this->collect([['kind' => 'pageview', 'path' => 'no-slash']])->assertUnprocessable();
        $this->collect(array_fill(0, 21, ['kind' => 'pageview', 'path' => '/a']))->assertUnprocessable();

        $this->assertSame(0, AnalyticsEvent::query()->count());
    }

    public function test_the_endpoint_is_csrf_protected_and_throttled_with_its_own_limiter(): void
    {
        $route = app('router')->getRoutes()->getByName('spa-analytics.collect');
        $middleware = app('router')->gatherRouteMiddleware($route);

        $this->assertContains(PreventRequestForgery::class, $middleware);
        $this->assertContains('Illuminate\Routing\Middleware\ThrottleRequests:spa-analytics-collect', $middleware);
    }

    public function test_a_visitor_over_the_limit_is_throttled(): void
    {
        config(['spa-analytics.collect.rate_limit_per_minute' => 2]);

        $this->collect([['kind' => 'pageview', 'path' => '/a']])->assertNoContent();
        $this->collect([['kind' => 'pageview', 'path' => '/b']])->assertNoContent();
        $this->collect([['kind' => 'pageview', 'path' => '/c']])->assertStatus(429);
    }
}
