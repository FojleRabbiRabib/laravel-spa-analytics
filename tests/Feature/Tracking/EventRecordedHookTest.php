<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use FojleRabbiRabib\LaravelSpaAnalytics\Events\EventRecorded;
use FojleRabbiRabib\LaravelSpaAnalytics\Facades\Analytics;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

class EventRecordedHookTest extends TestCase
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
     */
    private function collect(array $events): void
    {
        $this->withCredentials()->withCookie('spa_analytics_vid', self::VISITOR)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0'])
            ->postJson(route('spa-analytics.collect'), ['events' => $events])
            ->assertNoContent();
    }

    public function test_a_server_page_view_dispatches_the_event_with_the_stored_row(): void
    {
        $seen = [];
        Event::listen(EventRecorded::class, function (EventRecorded $event) use (&$seen): void {
            $seen[] = [$event->event->type, $event->event->path, $event->event->exists, $event->event->session_id];
        });

        $this->withCredentials()->withCookie('spa_analytics_vid', self::VISITOR)->get('/pricing')->assertOk();

        $this->assertCount(1, $seen);
        $this->assertSame([EventType::PageView, '/pricing', true], array_slice($seen[0], 0, 3));
        $this->assertNotNull($seen[0][3]);
    }

    public function test_a_collect_batch_dispatches_one_event_per_stored_row_and_none_for_a_viewport(): void
    {
        $count = 0;
        Event::listen(EventRecorded::class, function () use (&$count): void {
            $count++;
        });

        $this->collect([
            ['kind' => 'pageview', 'path' => '/docs'],
            ['kind' => 'viewport', 'path' => '/docs', 'width' => 1280],
            ['kind' => 'event', 'path' => '/docs', 'name' => 'clicked'],
        ]);

        $this->assertSame(AnalyticsEvent::query()->count(), $count);
        $this->assertSame(2, $count);
    }

    public function test_a_goal_from_the_facade_dispatches_the_event(): void
    {
        $this->collect([['kind' => 'pageview', 'path' => '/docs']]);

        $names = [];
        Event::listen(EventRecorded::class, function (EventRecorded $event) use (&$names): void {
            $names[] = [$event->event->type, $event->event->name];
        });

        Analytics::for(self::VISITOR)->goal('signup');

        $this->assertSame([[EventType::Goal, 'signup']], $names);
    }

    public function test_the_row_is_committed_and_the_visitor_lock_is_free_inside_the_listener(): void
    {
        $result = [];
        Event::listen(EventRecorded::class, function (EventRecorded $event) use (&$result): void {
            $lock = Cache::lock('session:'.$event->event->visitor_id, 5);
            $result = [AnalyticsEvent::query()->whereKey($event->event->getKey())->exists(), $lock->get()];
            $lock->release();
        });

        $this->collect([['kind' => 'pageview', 'path' => '/docs']]);

        $this->assertSame([true, true], $result);
    }

    public function test_a_throwing_listener_never_costs_a_row_or_the_response(): void
    {
        Log::spy();
        Event::listen(EventRecorded::class, function (): void {
            throw new \RuntimeException('secret bound value 203.0.113.9');
        });

        $this->collect([
            ['kind' => 'pageview', 'path' => '/docs'],
            ['kind' => 'event', 'path' => '/docs', 'name' => 'clicked'],
        ]);

        $this->assertSame(2, AnalyticsEvent::query()->count());
        Log::shouldHaveReceived('warning')->with('spa-analytics: event listener failed', ['exception' => \RuntimeException::class, 'code' => 0])->twice();
    }

    public function test_queue_mode_dispatches_the_event_from_the_job(): void
    {
        config()->set('spa-analytics.tracking.write_mode', 'queue');
        config()->set('queue.default', 'sync');

        $paths = [];
        Event::listen(EventRecorded::class, function (EventRecorded $event) use (&$paths): void {
            $paths[] = $event->event->path;
        });

        $this->withCredentials()->withCookie('spa_analytics_vid', self::VISITOR)->get('/pricing')->assertOk();

        $this->assertSame(['/pricing'], $paths);
    }

    public function test_in_sync_mode_inside_an_app_transaction_the_event_fires_before_that_transaction_commits(): void
    {
        $this->collect([['kind' => 'pageview', 'path' => '/docs']]);

        $fired = 0;
        Event::listen(EventRecorded::class, function () use (&$fired): void {
            $fired++;
        });

        try {
            DB::transaction(function (): void {
                Analytics::for(self::VISITOR)->goal('signup');

                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(1, $fired);
        $this->assertSame(0, AnalyticsEvent::query()->where('name', 'signup')->count());
    }

    public function test_the_event_survives_the_serialisation_a_queued_listener_goes_through(): void
    {
        $this->collect([['kind' => 'pageview', 'path' => '/docs']]);

        $restored = unserialize(serialize(new EventRecorded(AnalyticsEvent::query()->sole())));

        $this->assertSame('/docs', $restored->event->path);
        $this->assertTrue($restored->event->exists);
    }

    public function test_an_app_test_can_fake_the_event_without_registering_a_listener(): void
    {
        Event::fake([EventRecorded::class]);

        $this->collect([['kind' => 'pageview', 'path' => '/docs']]);

        Event::assertDispatched(EventRecorded::class, fn (EventRecorded $event): bool => $event->event->path === '/docs');
    }
}
