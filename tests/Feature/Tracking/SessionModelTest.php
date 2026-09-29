<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ReferrerType;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\Event;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\Session;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;

class SessionModelTest extends TestCase
{
    public function test_factory_persists_a_session_with_casts(): void
    {
        $session = Session::factory()->create()->fresh();

        $this->assertSame(ReferrerType::Direct, $session->referrer_type);
        $this->assertSame(1, $session->page_views);
        $this->assertTrue($session->is_new_visitor);
        $this->assertFalse($session->is_bot);
        $this->assertNotNull($session->started_at);
    }

    public function test_not_bots_scope_excludes_bot_sessions(): void
    {
        Session::factory()->create();
        Session::factory()->bot()->create();

        $this->assertSame(1, Session::query()->notBots()->count());
    }

    public function test_between_scope_filters_on_started_at_inclusively(): void
    {
        $from = now()->subDay()->startOfSecond();
        $to = now()->startOfSecond();

        Session::factory()->create(['started_at' => $from]);
        Session::factory()->create(['started_at' => $to]);
        Session::factory()->create(['started_at' => $from->copy()->subSecond()]);
        Session::factory()->create(['started_at' => $to->copy()->addSecond()]);

        $this->assertSame(2, Session::query()->between($from, $to)->count());
    }

    public function test_events_and_session_relate_both_ways(): void
    {
        $session = Session::factory()->create();
        $event = Event::factory()->create(['session_id' => $session->id]);

        $this->assertTrue($event->session->is($session));
        $this->assertTrue($session->events->first()->is($event));
    }
}
