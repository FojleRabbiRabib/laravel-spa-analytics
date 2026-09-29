<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\PageViewData;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ReferrerType;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\Event;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\Session;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\VisitorLink;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\EventStore;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\SessionTracker;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\VisitorLinkResolver;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class EventStoreTest extends TestCase
{
    private function pageView(string $visitor = 'visitor-1', string $at = '2026-09-29 10:00:00'): PageViewData
    {
        return new PageViewData(
            type: EventType::PageView,
            visitorId: $visitor,
            path: '/pricing',
            status: 200,
            referrerHost: null,
            referrerType: ReferrerType::Direct,
            utm: ['utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'utm_term' => null, 'utm_content' => null],
            language: 'en',
            ip: '203.0.113.9',
            userAgent: 'Mozilla/5.0',
            isBot: false,
            occurredAt: CarbonImmutable::parse($at),
        );
    }

    public function test_the_event_carries_the_session_id(): void
    {
        app(EventStore::class)->store($this->pageView());

        $this->assertSame(Session::query()->sole()->id, Event::query()->sole()->session_id);
    }

    public function test_views_within_the_timeout_share_a_session(): void
    {
        app(EventStore::class)->store($this->pageView());
        app(EventStore::class)->store($this->pageView('visitor-1', '2026-09-29 10:10:00'));

        $this->assertSame(1, Session::query()->count());
        $this->assertSame(2, Event::query()->count());
        $this->assertSame(2, Session::query()->sole()->page_views);
    }

    public function test_a_link_routes_the_write_to_the_adopted_visitor(): void
    {
        VisitorLink::factory()->create(['visitor_id' => 'new', 'linked_to' => 'old']);

        app(EventStore::class)->store($this->pageView('new'));

        $this->assertSame('old', Event::query()->sole()->visitor_id);
        $this->assertSame('old', Session::query()->sole()->visitor_id);
    }

    public function test_a_failed_insert_rolls_the_session_back(): void
    {
        Schema::drop('analytics_events');

        try {
            app(EventStore::class)->store($this->pageView());
            $this->fail('Expected the insert to fail.');
        } catch (\Throwable) {
            $this->assertSame(0, Session::query()->count());
        }
    }

    public function test_the_lock_is_held_until_the_event_is_inserted(): void
    {
        $lockAvailableAtInsert = null;

        Event::creating(function () use (&$lockAvailableAtInsert): void {
            $lock = Cache::lock('session:visitor-1', 1);
            $lockAvailableAtInsert = $lock->get();

            if ($lockAvailableAtInsert) {
                $lock->release();
            }
        });

        app(EventStore::class)->store($this->pageView());

        $this->assertFalse($lockAvailableAtInsert);
        $this->assertTrue(Cache::lock('session:visitor-1', 1)->get(), 'The lock must be released after the write.');
    }

    public function test_a_held_lock_times_out(): void
    {
        $lock = Cache::lock('session:visitor-1', 10);
        $lock->get();

        $store = new EventStore(app(VisitorLinkResolver::class), app(SessionTracker::class), 0);

        $this->expectException(LockTimeoutException::class);

        $store->store($this->pageView());
    }
}
