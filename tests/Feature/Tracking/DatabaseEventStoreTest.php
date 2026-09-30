<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\PageViewData;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ReferrerType;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\VisitorLink;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\DatabaseEventStore;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\SessionTracker;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\VisitorLinkResolver;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseEventStoreTest extends TestCase
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
        app(DatabaseEventStore::class)->store($this->pageView());

        $this->assertSame(AnalyticsSession::query()->sole()->id, AnalyticsEvent::query()->sole()->session_id);
    }

    public function test_views_within_the_timeout_share_a_session(): void
    {
        app(DatabaseEventStore::class)->store($this->pageView());
        app(DatabaseEventStore::class)->store($this->pageView('visitor-1', '2026-09-29 10:10:00'));

        $this->assertSame(1, AnalyticsSession::query()->count());
        $this->assertSame(2, AnalyticsEvent::query()->count());
        $this->assertSame(2, AnalyticsSession::query()->sole()->page_views);
    }

    public function test_a_link_routes_the_write_to_the_adopted_visitor(): void
    {
        VisitorLink::factory()->create(['visitor_id' => 'new', 'linked_to' => 'old']);

        app(DatabaseEventStore::class)->store($this->pageView('new'));

        $this->assertSame('old', AnalyticsEvent::query()->sole()->visitor_id);
        $this->assertSame('old', AnalyticsSession::query()->sole()->visitor_id);
    }

    public function test_a_failed_insert_rolls_the_session_back(): void
    {
        Schema::drop('analytics_events');

        try {
            app(DatabaseEventStore::class)->store($this->pageView());
            $this->fail('Expected the insert to fail.');
        } catch (\Throwable) {
            $this->assertSame(0, AnalyticsSession::query()->count());
        }
    }

    public function test_the_lock_is_held_until_the_event_is_inserted(): void
    {
        $lockHeldAtInsert = null;

        AnalyticsEvent::creating(function () use (&$lockHeldAtInsert): void {
            if (config('cache.default') === 'database') {
                // The database lock acquires by inserting and catching the duplicate-key error; inside this
                // open transaction a failed insert would abort it on PostgreSQL, so only read the lock row.
                $lockHeldAtInsert = DB::table('cache_locks')->where('key', 'like', '%session:visitor-1')->exists();

                return;
            }

            $lock = Cache::lock('session:visitor-1', 1);
            $acquired = $lock->get();
            $lockHeldAtInsert = ! $acquired;

            if ($acquired) {
                $lock->release();
            }
        });

        app(DatabaseEventStore::class)->store($this->pageView());

        $this->assertTrue($lockHeldAtInsert);
        $this->assertTrue(Cache::lock('session:visitor-1', 1)->get(), 'The lock must be released after the write.');
    }

    public function test_a_held_lock_times_out(): void
    {
        $lock = Cache::lock('session:visitor-1', 10);
        $lock->get();

        $store = new DatabaseEventStore(app(VisitorLinkResolver::class), app(SessionTracker::class), 0);

        $this->expectException(LockTimeoutException::class);

        $store->store($this->pageView());
    }
}
