<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ReferrerType;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;

class AnalyticsSessionModelTest extends TestCase
{
    public function test_factory_persists_a_session_with_casts(): void
    {
        $session = AnalyticsSession::factory()->create()->fresh();

        $this->assertSame(ReferrerType::Direct, $session->referrer_type);
        $this->assertSame(1, $session->page_views);
        $this->assertTrue($session->is_new_visitor);
        $this->assertFalse($session->is_bot);
        $this->assertNotNull($session->started_at);
    }

    public function test_not_bots_scope_excludes_bot_sessions(): void
    {
        AnalyticsSession::factory()->create();
        AnalyticsSession::factory()->bot()->create();

        $this->assertSame(1, AnalyticsSession::query()->notBots()->count());
    }

    public function test_between_scope_filters_on_started_at_inclusively(): void
    {
        $from = now()->subDay()->startOfSecond();
        $to = now()->startOfSecond();

        AnalyticsSession::factory()->create(['started_at' => $from]);
        AnalyticsSession::factory()->create(['started_at' => $to]);
        AnalyticsSession::factory()->create(['started_at' => $from->copy()->subSecond()]);
        AnalyticsSession::factory()->create(['started_at' => $to->copy()->addSecond()]);

        $this->assertSame(2, AnalyticsSession::query()->between($from, $to)->count());
    }

    public function test_events_and_session_relate_both_ways(): void
    {
        $session = AnalyticsSession::factory()->create();
        $event = AnalyticsEvent::factory()->create(['session_id' => $session->id]);

        $this->assertTrue($event->session->is($session));
        $this->assertTrue($session->events->first()->is($event));
    }
}
