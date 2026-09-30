<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\CustomEventData;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\VisitorLink;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\DatabaseEventStore;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;

class CustomEventStoreTest extends TestCase
{
    private function custom(string $visitor = 'visitor-1', string $at = '2026-09-29 10:00:00', EventType $type = EventType::Custom): CustomEventData
    {
        return new CustomEventData(
            type: $type,
            visitorId: $visitor,
            name: 'signup_clicked',
            value: $type === EventType::Goal ? 49.5 : null,
            properties: ['plan' => 'pro'],
            path: '/pricing',
            language: 'en',
            ip: '203.0.113.9',
            userAgent: 'Mozilla/5.0',
            isBot: false,
            occurredAt: CarbonImmutable::parse($at),
        );
    }

    private function existingSession(string $start, string $end, string $visitor = 'visitor-1'): AnalyticsSession
    {
        return AnalyticsSession::factory()->create([
            'visitor_id' => $visitor,
            'started_at' => Carbon::parse($start),
            'last_seen_at' => Carbon::parse($end),
            'page_views' => 3,
            'exit_path' => '/last',
        ]);
    }

    public function test_a_custom_event_is_stored_with_its_name_and_properties(): void
    {
        app(DatabaseEventStore::class)->store($this->custom());

        $event = AnalyticsEvent::query()->sole();

        $this->assertSame(EventType::Custom, $event->type);
        $this->assertSame('signup_clicked', $event->name);
        $this->assertSame(['plan' => 'pro'], $event->properties);
        $this->assertSame('/pricing', $event->path);
        $this->assertNull($event->status);
        $this->assertNull($event->value);
    }

    public function test_a_goal_is_stored_with_its_value(): void
    {
        app(DatabaseEventStore::class)->store($this->custom(type: EventType::Goal));

        $event = AnalyticsEvent::query()->sole();

        $this->assertSame(EventType::Goal, $event->type);
        $this->assertSame('49.50', $event->value);
    }

    public function test_the_event_attaches_to_a_session_that_is_still_active(): void
    {
        $session = $this->existingSession('2026-09-29 09:40:00', '2026-09-29 09:50:00');

        app(DatabaseEventStore::class)->store($this->custom(at: '2026-09-29 10:20:00'));

        $this->assertSame($session->id, AnalyticsEvent::query()->sole()->session_id);
    }

    public function test_a_session_gap_of_exactly_the_timeout_still_attaches(): void
    {
        config()->set('spa-analytics.sessions.timeout_minutes', 30);
        $session = $this->existingSession('2026-09-29 09:40:00', '2026-09-29 09:50:00');

        app(DatabaseEventStore::class)->store($this->custom(at: '2026-09-29 10:20:00'));

        $this->assertSame($session->id, AnalyticsEvent::query()->sole()->session_id);
    }

    public function test_an_event_outside_the_timeout_has_no_session(): void
    {
        config()->set('spa-analytics.sessions.timeout_minutes', 30);
        $this->existingSession('2026-09-29 09:40:00', '2026-09-29 09:50:00');

        app(DatabaseEventStore::class)->store($this->custom(at: '2026-09-29 10:20:01'));

        $this->assertNull(AnalyticsEvent::query()->sole()->session_id);
    }

    public function test_an_event_without_any_session_creates_none(): void
    {
        app(DatabaseEventStore::class)->store($this->custom());

        $this->assertNull(AnalyticsEvent::query()->sole()->session_id);
        $this->assertSame(0, AnalyticsSession::query()->count());
    }

    public function test_the_session_row_is_left_untouched(): void
    {
        $session = $this->existingSession('2026-09-29 09:40:00', '2026-09-29 09:50:00');

        app(DatabaseEventStore::class)->store($this->custom(at: '2026-09-29 10:00:00'));

        $fresh = $session->fresh();
        $this->assertSame(3, $fresh->page_views);
        $this->assertSame('/last', $fresh->exit_path);
        $this->assertEquals($session->last_seen_at, $fresh->last_seen_at);
    }

    public function test_another_visitors_session_is_not_used(): void
    {
        $this->existingSession('2026-09-29 09:40:00', '2026-09-29 09:50:00', 'someone-else');

        app(DatabaseEventStore::class)->store($this->custom());

        $this->assertNull(AnalyticsEvent::query()->sole()->session_id);
    }

    public function test_a_link_routes_the_custom_event_to_the_adopted_visitor(): void
    {
        VisitorLink::factory()->create(['visitor_id' => 'new', 'linked_to' => 'old']);
        $session = $this->existingSession('2026-09-29 09:50:00', '2026-09-29 09:55:00', 'old');

        app(DatabaseEventStore::class)->store($this->custom('new'));

        $event = AnalyticsEvent::query()->sole();
        $this->assertSame('old', $event->visitor_id);
        $this->assertSame($session->id, $event->session_id);
    }
}
