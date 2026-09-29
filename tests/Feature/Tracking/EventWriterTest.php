<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\PageViewData;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ReferrerType;
use FojleRabbiRabib\LaravelSpaAnalytics\Jobs\WriteEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\Event;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\EventWriter;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

class EventWriterTest extends TestCase
{
    private function pageView(): PageViewData
    {
        return new PageViewData(
            type: EventType::PageView,
            visitorId: 'visitor-1',
            path: '/pricing',
            status: 200,
            referrerHost: null,
            referrerType: ReferrerType::Direct,
            utm: ['utm_source' => 'news', 'utm_medium' => null, 'utm_campaign' => null, 'utm_term' => null, 'utm_content' => null],
            language: 'en',
            ip: '203.0.113.9',
            userAgent: 'Mozilla/5.0',
            isBot: false,
            occurredAt: CarbonImmutable::parse('2026-09-29 10:00:00'),
        );
    }

    public function test_sync_mode_inserts_immediately(): void
    {
        config()->set('laravel-spa-analytics.tracking.write_mode', 'sync');

        app(EventWriter::class)->write($this->pageView());

        $this->assertSame(1, Event::query()->count());
        $this->assertSame('news', Event::query()->first()->utm_source);
    }

    public function test_queue_mode_pushes_a_job_on_the_configured_connection_and_queue(): void
    {
        Queue::fake();
        config()->set('laravel-spa-analytics.tracking.write_mode', 'queue');
        config()->set('laravel-spa-analytics.tracking.connection', 'redis');
        config()->set('laravel-spa-analytics.tracking.queue', 'analytics');

        app(EventWriter::class)->write($this->pageView());

        Queue::assertPushed(WriteEvent::class, fn (WriteEvent $job): bool => $job->connection === 'redis' && $job->queue === 'analytics');
        $this->assertSame(0, Event::query()->count());
    }

    public function test_queue_mode_survives_real_job_serialization(): void
    {
        config()->set('laravel-spa-analytics.tracking.write_mode', 'queue');
        config()->set('queue.default', 'sync');

        app(EventWriter::class)->write($this->pageView());

        $event = Event::query()->sole();

        $this->assertSame(EventType::PageView, $event->type);
        $this->assertSame(ReferrerType::Direct, $event->referrer_type);
        $this->assertSame('2026-09-29 10:00:00', $event->occurred_at->toDateTimeString());
    }

    public function test_the_job_inserts_the_event(): void
    {
        (new WriteEvent($this->pageView()->toArray()))->handle();

        $this->assertSame(1, Event::query()->count());
        $this->assertSame('/pricing', Event::query()->first()->path);
    }

    public function test_defer_mode_writes_after_the_deferred_callbacks_run(): void
    {
        config()->set('laravel-spa-analytics.tracking.write_mode', 'defer');

        app(EventWriter::class)->write($this->pageView());

        $this->assertSame(0, Event::query()->count());

        app(DeferredCallbackCollection::class)->invoke();

        $this->assertSame(1, Event::query()->count());
    }

    public function test_defer_mode_callbacks_are_marked_always_so_error_responses_are_written(): void
    {
        config()->set('laravel-spa-analytics.tracking.write_mode', 'defer');

        app(EventWriter::class)->write($this->pageView());

        $callbacks = app(DeferredCallbackCollection::class);
        $this->assertTrue($callbacks[count($callbacks) - 1]->always);
    }

    public function test_unknown_write_mode_falls_back_to_defer(): void
    {
        config()->set('laravel-spa-analytics.tracking.write_mode', 'bogus');

        app(EventWriter::class)->write($this->pageView());

        $this->assertSame(0, Event::query()->count());

        app(DeferredCallbackCollection::class)->invoke();

        $this->assertSame(1, Event::query()->count());
    }

    public function test_a_failing_insert_is_swallowed_and_logged_without_the_message(): void
    {
        Log::spy();
        config()->set('laravel-spa-analytics.tracking.write_mode', 'sync');
        Schema::drop('analytics_events');

        app(EventWriter::class)->write($this->pageView());

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => array_keys($context) === ['exception', 'code']
                && ! str_contains(json_encode($context), '203.0.113.9'),
        );
    }

    public function test_a_failing_deferred_insert_is_swallowed_and_logged(): void
    {
        Log::spy();
        config()->set('laravel-spa-analytics.tracking.write_mode', 'defer');
        Schema::drop('analytics_events');

        app(EventWriter::class)->write($this->pageView());
        app(DeferredCallbackCollection::class)->invoke();

        Log::shouldHaveReceived('warning')->once();
    }
}
