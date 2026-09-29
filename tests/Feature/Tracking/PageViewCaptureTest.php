<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\PageViewData;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ReferrerType;
use FojleRabbiRabib\LaravelSpaAnalytics\Http\Middleware\CapturePageView;
use FojleRabbiRabib\LaravelSpaAnalytics\Http\Middleware\ResolveVisitorIdentity;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\Event;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\Session;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\EventStore;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\EventWriter;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

class PageViewCaptureTest extends TestCase
{
    private const FIREFOX = 'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('spa-analytics.tracking.write_mode', 'sync');
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->group(function ($router): void {
            $router->get('/page', fn () => '<html><body>hello</body></html>');
            $router->get('/json', fn () => response()->json(['ok' => true]));
            $router->get('/missing', fn () => abort(404));
            $router->get('/boom', fn () => throw new \RuntimeException('boom'));
            $router->get('/redirect', fn () => redirect('/page'));
            $router->post('/submit', fn () => '<html>posted</html>');
            $router->get('/up', fn () => '<html>up</html>');
            $router->get('/spa-analytics/other', fn () => '<html>own</html>');
            $router->get('/reset-password/{token}', fn () => '<html>reset</html>');
            $router->get('/password/reset/{token}', fn () => '<html>reset</html>');
            $router->get('/conflict', fn () => response('<html>x</html>', 409));
        });

        $router->fallback(fn () => abort(404))->middleware('web');

        $router->middleware(CapturePageView::class)->get('/no-identity', fn () => '<html>anon</html>');
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function visit(string $uri, array $headers = []): TestResponse
    {
        return $this->withHeaders(['User-Agent' => self::FIREFOX, ...$headers])->get($uri);
    }

    public function test_html_page_view_is_recorded_with_the_visitor_id(): void
    {
        $response = $this->visit('/page', ['Accept-Language' => 'fr-CA,fr;q=0.9']);

        $event = Event::query()->sole();

        $this->assertSame(EventType::PageView, $event->type);
        $this->assertSame('/page', $event->path);
        $this->assertSame(200, $event->status);
        $this->assertSame($response->getCookie('spa_analytics_vid')->getValue(), $event->visitor_id);
        $this->assertTrue(Str::isUuid($event->visitor_id));
        $this->assertSame('fr-CA', $event->language);
        $this->assertSame('127.0.0.1', $event->ip);
        $this->assertSame(self::FIREFOX, $event->user_agent);
        $this->assertFalse($event->is_bot);
        $this->assertSame(ReferrerType::Direct, $event->referrer_type);
        $this->assertNull($event->referrer_host);
        $this->assertSame(Session::query()->sole()->id, $event->session_id);
    }

    public function test_query_string_is_stripped_and_utm_is_captured(): void
    {
        $this->visit('/page?utm_source=news&utm_medium=email&secret=token123');

        $event = Event::query()->sole();

        $this->assertSame('/page', $event->path);
        $this->assertSame('news', $event->utm_source);
        $this->assertSame('email', $event->utm_medium);
        $this->assertNull($event->utm_campaign);
        $this->assertStringNotContainsString('token123', json_encode($event->getAttributes()));
    }

    public function test_error_pages_from_matched_routes_are_recorded(): void
    {
        $this->visit('/missing');
        $this->visit('/boom');

        $this->assertSame([404, 500], Event::query()->orderBy('id')->pluck('status')->all());
    }

    public function test_a_web_group_fallback_route_records_unmatched_urls(): void
    {
        $this->visit('/no/such/page')->assertNotFound();

        $event = Event::query()->sole();

        $this->assertSame('/no/such/page', $event->path);
        $this->assertSame(404, $event->status);
    }

    public function test_asset_requests_hitting_the_fallback_are_skipped(): void
    {
        $this->visit('/favicon.ico', ['Accept' => 'image/avif,image/webp,*/*'])->assertNotFound();
        $this->visit('/missing.png', ['Sec-Fetch-Dest' => 'image'])->assertNotFound();
        $this->visit('/app.js.map', ['Accept' => '*/*'])->assertNotFound();

        $this->assertSame(0, Event::query()->count());
    }

    public function test_document_navigation_with_fetch_metadata_is_recorded(): void
    {
        $this->visit('/page', ['Sec-Fetch-Dest' => 'document']);

        $this->assertSame(1, Event::query()->count());
    }

    public function test_referrer_is_classified(): void
    {
        $this->visit('/page', ['Referer' => 'https://www.google.com/search?q=x']);
        $this->visit('/page', ['Referer' => 'http://localhost/other']);
        $this->visit('/page', ['Referer' => 'https://blog.example.org/post']);

        $events = Event::query()->orderBy('id')->get();

        $this->assertSame(ReferrerType::Search, $events[0]->referrer_type);
        $this->assertSame('google.com', $events[0]->referrer_host);
        $this->assertSame(ReferrerType::Direct, $events[1]->referrer_type);
        $this->assertNull($events[1]->referrer_host);
        $this->assertSame(ReferrerType::Referral, $events[2]->referrer_type);
        $this->assertSame('blog.example.org', $events[2]->referrer_host);
    }

    public function test_inertia_visit_is_recorded(): void
    {
        $this->visit('/json', ['X-Inertia' => 'true']);

        $this->assertSame('/json', Event::query()->sole()->path);
    }

    public function test_inertia_partial_reload_is_skipped(): void
    {
        $this->visit('/json', ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Home', 'X-Inertia-Partial-Data' => 'users']);

        $this->assertSame(0, Event::query()->count());
    }

    public function test_inertia_version_conflict_is_skipped(): void
    {
        $this->visit('/conflict', ['X-Inertia' => 'true']);

        $this->assertSame(0, Event::query()->count());
    }

    public function test_prefetch_is_skipped(): void
    {
        $this->visit('/page', ['Purpose' => 'prefetch']);
        $this->visit('/page', ['Sec-Purpose' => 'prefetch;prerender']);

        $this->assertSame(0, Event::query()->count());
    }

    public function test_non_page_requests_are_skipped(): void
    {
        $this->visit('/redirect');
        $this->post('/submit');
        $this->visit('/json');
        $this->visit('/page', ['X-Requested-With' => 'XMLHttpRequest']);

        $this->assertSame(0, Event::query()->count());
    }

    public function test_excluded_paths_are_skipped(): void
    {
        $this->visit('/up');
        $this->visit('/spa-analytics/other');
        $this->visit('/reset-password/secret-token');
        $this->visit('/password/reset/secret-token');

        $this->assertSame(0, Event::query()->count());
    }

    public function test_bots_are_recorded_and_flagged(): void
    {
        $this->visit('/page', ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)']);

        $this->assertTrue(Event::query()->sole()->is_bot);
    }

    public function test_user_agent_is_truncated(): void
    {
        $this->visit('/page', ['User-Agent' => 'Mozilla/'.str_repeat('a', 1000)]);

        $this->assertSame(512, strlen(Event::query()->sole()->user_agent));
    }

    public function test_disabled_config_records_nothing(): void
    {
        config()->set('spa-analytics.enabled', false);

        $this->visit('/page');

        $this->assertSame(0, Event::query()->count());
    }

    public function test_requests_without_an_identity_are_skipped(): void
    {
        $this->visit('/no-identity')->assertOk();

        $this->assertSame(0, Event::query()->count());
    }

    public function test_middleware_is_registered_after_identity_on_the_web_group(): void
    {
        $group = $this->app->make(Kernel::class)->getMiddlewareGroups()['web'];

        $this->assertContains(CapturePageView::class, $group);
        $this->assertGreaterThan(
            array_search(ResolveVisitorIdentity::class, $group, true),
            array_search(CapturePageView::class, $group, true),
        );
    }

    public function test_a_failing_writer_never_breaks_the_response(): void
    {
        Log::spy();
        $this->app->bind(EventWriter::class, fn ($app) => new class($app->make(EventStore::class)) extends EventWriter
        {
            public function write(PageViewData $data): void
            {
                throw new \RuntimeException('failed for 127.0.0.1');
            }
        });

        $this->visit('/page')->assertOk();

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => array_keys($context) === ['exception', 'code']
                && ! str_contains(json_encode($context), '127.0.0.1'),
        );
    }

    public function test_a_failing_insert_never_breaks_the_response(): void
    {
        Log::spy();
        Schema::drop('analytics_events');

        $this->visit('/page')->assertOk();

        Log::shouldHaveReceived('warning')->once();
    }
}
