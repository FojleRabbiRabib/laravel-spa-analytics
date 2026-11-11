<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\EventStore;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\CustomEventData;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\PageViewData;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ReferrerType;
use FojleRabbiRabib\LaravelSpaAnalytics\Events\EventRecorded;
use FojleRabbiRabib\LaravelSpaAnalytics\Jobs\WriteEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\DatabaseEventStore;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\EventWriter;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\SessionTracker;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\VisitorLinkResolver;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class QueueSafetyTest extends TestCase
{
    protected function tearDown(): void
    {
        AnalyticsEvent::flushEventListeners();

        parent::tearDown();
    }

    private function pageView(): PageViewData
    {
        return new PageViewData(
            type: EventType::PageView,
            visitorId: 'visitor-1',
            path: '/pricing',
            status: 200,
            referrerHost: null,
            referrerType: ReferrerType::Direct,
            utm: ['utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'utm_term' => null, 'utm_content' => null],
            language: 'en',
            ip: '203.0.113.9',
            userAgent: 'Mozilla/5.0',
            isBot: false,
            occurredAt: CarbonImmutable::parse('2026-09-29 10:00:00'),
        );
    }

    private function goal(): CustomEventData
    {
        return new CustomEventData(
            type: EventType::Goal,
            visitorId: 'visitor-1',
            name: 'purchase',
            value: 49.5,
            properties: [],
            path: null,
            language: null,
            ip: null,
            userAgent: null,
            isBot: false,
            occurredAt: CarbonImmutable::parse('2026-09-29 10:00:00'),
        );
    }

    private function queuedJob(PageViewData|CustomEventData $data): WriteEvent
    {
        Queue::fake();
        config()->set('spa-analytics.tracking.write_mode', 'queue');

        app(EventWriter::class)->write($data);

        return Queue::pushed(WriteEvent::class)->sole();
    }

    public function test_the_writer_gives_every_event_its_own_stable_id(): void
    {
        config()->set('spa-analytics.tracking.write_mode', 'sync');

        app(EventWriter::class)->write($this->pageView());
        app(EventWriter::class)->write($this->goal());

        $ids = AnalyticsEvent::query()->pluck('event_id');

        $this->assertCount(2, $ids->unique());
        $this->assertSame([26, 26], $ids->map(fn (?string $id): int => strlen((string) $id))->all());
    }

    public function test_a_replayed_page_view_job_stores_one_event_and_counts_once(): void
    {
        Event::fake([EventRecorded::class]);
        $job = $this->queuedJob($this->pageView());

        $job->handle(app(EventStore::class));
        $job->handle(app(EventStore::class));

        $this->assertSame(1, AnalyticsEvent::query()->count());
        $this->assertSame(1, AnalyticsSession::query()->sole()->page_views);
        Event::assertDispatchedTimes(EventRecorded::class, 1);
    }

    public function test_a_replayed_custom_event_job_stores_one_event(): void
    {
        $job = $this->queuedJob($this->goal());

        $job->handle(app(EventStore::class));
        $job->handle(app(EventStore::class));

        $this->assertSame(1, AnalyticsEvent::query()->count());
    }

    public function test_a_job_retried_after_a_held_lock_stores_exactly_once(): void
    {
        $job = $this->queuedJob($this->pageView());
        $this->app->bind(EventStore::class, fn ($app) => new DatabaseEventStore(
            $app->make(VisitorLinkResolver::class),
            $app->make(SessionTracker::class),
            0,
        ));
        $lock = Cache::lock('session:visitor-1', 10);
        $lock->get();

        try {
            $job->handle(app(EventStore::class));
            $this->fail('The held lock should have made the job fail so the queue retries it.');
        } catch (LockTimeoutException) {
            $this->assertSame(0, AnalyticsEvent::query()->count());
        }

        $lock->release();
        $job->handle(app(EventStore::class));
        $job->handle(app(EventStore::class));

        $this->assertSame(1, AnalyticsEvent::query()->count());
    }

    public function test_a_run_that_fails_after_the_session_change_is_rolled_back_and_the_retry_counts_once(): void
    {
        $job = $this->queuedJob($this->pageView());
        $failOnce = true;
        AnalyticsEvent::creating(function () use (&$failOnce): void {
            if ($failOnce) {
                $failOnce = false;

                throw new \RuntimeException('database went away');
            }
        });

        try {
            $job->handle(app(EventStore::class));
            $this->fail('The first run should have failed.');
        } catch (\RuntimeException) {
            $this->assertSame(0, AnalyticsSession::query()->count());
        }

        $job->handle(app(EventStore::class));

        $this->assertSame(1, AnalyticsEvent::query()->count());
        $this->assertSame(1, AnalyticsSession::query()->sole()->page_views);
    }

    public function test_two_distinct_events_with_the_same_content_are_both_stored(): void
    {
        config()->set('spa-analytics.tracking.write_mode', 'sync');

        app(EventWriter::class)->write($this->goal());
        app(EventWriter::class)->write($this->goal());

        $this->assertSame(2, AnalyticsEvent::query()->count());
    }

    public function test_a_payload_queued_before_the_upgrade_has_no_id_and_is_still_stored(): void
    {
        $payload = $this->goal()->toArray();
        unset($payload['event_id']);

        (new WriteEvent($payload))->handle(app(EventStore::class));
        (new WriteEvent($payload))->handle(app(EventStore::class));

        $this->assertSame(2, AnalyticsEvent::query()->count());
        $this->assertNull(AnalyticsEvent::query()->first()->event_id);
    }

    public function test_the_database_refuses_a_second_event_with_the_same_id_but_allows_many_without_one(): void
    {
        AnalyticsEvent::factory()->count(2)->create(['event_id' => null]);
        AnalyticsEvent::factory()->create(['event_id' => 'same']);

        $this->expectException(UniqueConstraintViolationException::class);

        AnalyticsEvent::factory()->create(['event_id' => 'same']);
    }

    public function test_a_write_that_loses_the_race_on_the_unique_id_is_dropped_without_an_announcement(): void
    {
        Event::fake([EventRecorded::class]);
        AnalyticsEvent::creating(function (): never {
            throw new UniqueConstraintViolationException('testing', 'insert', [], new \PDOException('duplicate'));
        });

        app(EventStore::class)->store($this->pageView()->withEventId('01JEXAMPLE0000000000000000'));

        $this->assertSame(0, AnalyticsEvent::query()->count());
        $this->assertSame(0, AnalyticsSession::query()->count());
        Event::assertNotDispatched(EventRecorded::class);
    }

    public function test_the_job_is_encrypted_and_retries_with_a_backoff_and_a_timeout(): void
    {
        $job = new WriteEvent($this->goal()->toArray());

        $this->assertInstanceOf(ShouldBeEncrypted::class, $job);
        $this->assertSame(5, $job->tries);
        $this->assertSame(30, $job->timeout);
        $this->assertSame([5, 30, 120, 300], $job->backoff());
    }

    public function test_a_failed_job_logs_only_the_exception_class_and_code(): void
    {
        Log::spy();

        (new WriteEvent($this->goal()->toArray()))->failed(new \RuntimeException('bound value 203.0.113.9', 7));

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $context === ['exception' => \RuntimeException::class, 'code' => 7]
                && ! str_contains($message, '203.0.113.9'),
        );
    }
}
