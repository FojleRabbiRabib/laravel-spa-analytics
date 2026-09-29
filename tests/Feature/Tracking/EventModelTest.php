<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ReferrerType;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\Event;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;

class EventModelTest extends TestCase
{
    public function test_factory_persists_an_event_with_enum_casts(): void
    {
        $event = Event::factory()->create()->fresh();

        $this->assertSame(EventType::PageView, $event->type);
        $this->assertSame(ReferrerType::Direct, $event->referrer_type);
        $this->assertFalse($event->is_bot);
        $this->assertSame(200, $event->status);
    }

    public function test_properties_are_cast_to_array(): void
    {
        $event = Event::factory()->create(['properties' => ['plan' => 'pro']])->fresh();

        $this->assertSame(['plan' => 'pro'], $event->properties);
    }

    public function test_not_bots_scope_excludes_bot_rows(): void
    {
        Event::factory()->create();
        Event::factory()->bot()->create();

        $this->assertSame(1, Event::query()->notBots()->count());
    }

    public function test_between_scope_is_inclusive(): void
    {
        $from = now()->subDay()->startOfSecond();
        $to = now()->startOfSecond();

        Event::factory()->create(['occurred_at' => $from]);
        Event::factory()->create(['occurred_at' => $to]);
        Event::factory()->create(['occurred_at' => $from->copy()->subSecond()]);
        Event::factory()->create(['occurred_at' => $to->copy()->addSecond()]);

        $this->assertSame(2, Event::query()->between($from, $to)->count());
    }
}
