<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\EventStore;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\PageViewData;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ReferrerType;
use FojleRabbiRabib\LaravelSpaAnalytics\Facades\Analytics;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use FojleRabbiRabib\LaravelSpaAnalytics\Support\AnalyticsDatabase;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Database\QueryException;

class SessionConcurrencyTest extends TestCase
{
    private const VISITOR = '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';

    protected function tearDown(): void
    {
        AnalyticsEvent::flushEventListeners();

        parent::tearDown();
    }

    private function pageView(): PageViewData
    {
        return new PageViewData(
            type: EventType::PageView,
            visitorId: self::VISITOR,
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

    public function test_a_deadlock_while_storing_is_retried_and_the_event_is_kept_once(): void
    {
        $deadlocks = 2;
        AnalyticsEvent::creating(function () use (&$deadlocks): void {
            if ($deadlocks-- > 0) {
                throw new QueryException('testing', 'insert', [], new \PDOException('Deadlock found when trying to get lock; try restarting transaction'));
            }
        });

        app(EventStore::class)->store($this->pageView());

        $this->assertSame(1, AnalyticsEvent::query()->count());
        $this->assertSame(1, AnalyticsSession::query()->sole()->page_views);
    }

    public function test_a_deadlock_that_keeps_happening_is_not_swallowed(): void
    {
        AnalyticsEvent::creating(function (): never {
            throw new QueryException('testing', 'insert', [], new \PDOException('Deadlock found when trying to get lock; try restarting transaction'));
        });

        $this->expectException(QueryException::class);

        app(EventStore::class)->store($this->pageView());
    }

    public function test_the_session_row_is_locked_while_a_page_view_is_attached(): void
    {
        if (! $this->usesRealEngine()) {
            $this->markTestSkipped('SQLite has no row locks; the lock clause is checked on the real engines in CI.');
        }

        app(EventStore::class)->store($this->pageView());
        $queries = [];
        AnalyticsDatabase::connection()->listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        app(EventStore::class)->store($this->pageView()->withEventId('01JEXAMPLE0000000000000000'));

        $locked = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'analytics_sessions') && str_contains($sql, 'for update'));

        $this->assertNotEmpty($locked);
    }

    public function test_an_upper_case_visitor_id_is_stored_in_lower_case(): void
    {
        config()->set('spa-analytics.tracking.write_mode', 'sync');

        Analytics::for(strtoupper(self::VISITOR))->track('clicked');

        $this->assertSame(self::VISITOR, AnalyticsEvent::query()->sole()->visitor_id);
    }
}
