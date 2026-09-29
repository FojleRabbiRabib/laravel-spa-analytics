<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\PageViewData;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ReferrerType;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\Session;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\SessionTracker;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;

class SessionTrackerTest extends TestCase
{
    private const BASE = '2026-09-29 10:00:00';

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function pageView(string $at = self::BASE, string $path = '/a', string $visitor = 'v1', array $overrides = []): PageViewData
    {
        return PageViewData::fromArray([
            'type' => EventType::PageView,
            'visitor_id' => $visitor,
            'path' => $path,
            'status' => 200,
            'referrer_host' => 'google.com',
            'referrer_type' => ReferrerType::Search,
            'utm_source' => 'news',
            'language' => 'en',
            'ip' => '203.0.113.9',
            'user_agent' => 'Mozilla/5.0',
            'is_bot' => false,
            'occurred_at' => CarbonImmutable::parse($at),
            ...$overrides,
        ]);
    }

    private function tracker(): SessionTracker
    {
        return app(SessionTracker::class);
    }

    public function test_the_first_view_opens_a_session(): void
    {
        $session = $this->tracker()->attach($this->pageView());

        $this->assertSame('v1', $session->visitor_id);
        $this->assertSame('/a', $session->entry_path);
        $this->assertSame('/a', $session->exit_path);
        $this->assertSame(1, $session->page_views);
        $this->assertTrue($session->is_new_visitor);
        $this->assertSame(ReferrerType::Search, $session->referrer_type);
        $this->assertSame('google.com', $session->referrer_host);
        $this->assertSame('news', $session->utm_source);
        $this->assertSame(self::BASE, $session->started_at->toDateTimeString());
    }

    public function test_a_view_within_the_timeout_continues_the_session(): void
    {
        $first = $this->tracker()->attach($this->pageView());
        $second = $this->tracker()->attach($this->pageView('2026-09-29 10:20:00', '/b'));

        $this->assertTrue($second->is($first));
        $this->assertSame(1, Session::query()->count());

        $session = $second->fresh();
        $this->assertSame(2, $session->page_views);
        $this->assertSame('/a', $session->entry_path);
        $this->assertSame('/b', $session->exit_path);
        $this->assertSame('2026-09-29 10:20:00', $session->last_seen_at->toDateTimeString());
        $this->assertSame(self::BASE, $session->started_at->toDateTimeString());
    }

    public function test_a_view_after_the_gap_opens_a_new_session_for_a_returning_visitor(): void
    {
        $this->tracker()->attach($this->pageView());
        $second = $this->tracker()->attach($this->pageView('2026-09-29 11:00:00', '/c'));

        $this->assertSame(2, Session::query()->count());
        $this->assertFalse($second->is_new_visitor);
        $this->assertSame('/c', $second->entry_path);
    }

    public function test_the_timeout_is_configurable(): void
    {
        config()->set('laravel-spa-analytics.sessions.timeout_minutes', 5);

        $this->tracker()->attach($this->pageView());
        $this->tracker()->attach($this->pageView('2026-09-29 10:06:00'));

        $this->assertSame(2, Session::query()->count());
    }

    public function test_referrer_and_utm_come_from_the_first_view_only(): void
    {
        $this->tracker()->attach($this->pageView());
        $this->tracker()->attach($this->pageView('2026-09-29 10:05:00', '/b', overrides: [
            'referrer_host' => null,
            'referrer_type' => ReferrerType::Direct,
            'utm_source' => 'other',
        ]));

        $session = Session::query()->sole();

        $this->assertSame('google.com', $session->referrer_host);
        $this->assertSame(ReferrerType::Search, $session->referrer_type);
        $this->assertSame('news', $session->utm_source);
    }

    public function test_a_slightly_earlier_view_moves_the_start_back_but_not_the_exit(): void
    {
        $this->tracker()->attach($this->pageView('2026-09-29 10:00:05', '/b'));
        $this->tracker()->attach($this->pageView('2026-09-29 10:00:00', '/a'));

        $session = Session::query()->sole();

        $this->assertSame(2, $session->page_views);
        $this->assertSame('/a', $session->entry_path);
        $this->assertSame('/b', $session->exit_path);
        $this->assertSame('2026-09-29 10:00:00', $session->started_at->toDateTimeString());
        $this->assertSame('2026-09-29 10:00:05', $session->last_seen_at->toDateTimeString());
    }

    public function test_a_view_far_older_than_the_session_opens_its_own_session(): void
    {
        $latest = $this->tracker()->attach($this->pageView('2026-09-29 10:00:00', '/now'));
        $old = $this->tracker()->attach($this->pageView('2026-09-29 06:00:00', '/old'));

        $this->assertFalse($old->is($latest));
        $this->assertSame(2, Session::query()->count());

        $latest = $latest->fresh();
        $this->assertSame(1, $latest->page_views);
        $this->assertSame('/now', $latest->entry_path);
    }

    public function test_visitors_have_separate_sessions(): void
    {
        $this->tracker()->attach($this->pageView(visitor: 'v1'));
        $second = $this->tracker()->attach($this->pageView(visitor: 'v2'));

        $this->assertSame(2, Session::query()->count());
        $this->assertTrue($second->is_new_visitor);
    }

    public function test_the_bot_flag_is_copied(): void
    {
        $session = $this->tracker()->attach($this->pageView(overrides: ['is_bot' => true]));

        $this->assertTrue($session->is_bot);
    }
}
