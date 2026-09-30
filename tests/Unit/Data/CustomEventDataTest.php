<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Unit\Data;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\CustomEventData;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use PHPUnit\Framework\TestCase;

class CustomEventDataTest extends TestCase
{
    private function data(EventType $type = EventType::Goal): CustomEventData
    {
        return new CustomEventData(
            type: $type,
            visitorId: 'visitor-1',
            name: 'purchase',
            value: 49.5,
            properties: ['plan' => 'pro'],
            path: '/checkout',
            language: 'en',
            ip: '203.0.113.9',
            userAgent: 'Mozilla/5.0',
            isBot: false,
            occurredAt: CarbonImmutable::parse('2026-09-29 10:00:00'),
        );
    }

    public function test_to_array_is_column_keyed_with_no_status(): void
    {
        $array = $this->data()->toArray();

        $this->assertSame(EventType::Goal, $array['type']);
        $this->assertSame('purchase', $array['name']);
        $this->assertSame(49.5, $array['value']);
        $this->assertSame(['plan' => 'pro'], $array['properties']);
        $this->assertSame('/checkout', $array['path']);
        $this->assertNull($array['status']);
    }

    public function test_empty_properties_become_null(): void
    {
        $data = new CustomEventData(EventType::Custom, 'v', 'clicked', null, [], null, null, null, null, false, CarbonImmutable::now());

        $this->assertNull($data->toArray()['properties']);
    }

    public function test_from_array_round_trips(): void
    {
        $original = $this->data();

        $copy = CustomEventData::fromArray($original->toArray());

        $this->assertEquals($original, $copy);
    }

    public function test_from_array_accepts_plain_strings_as_a_queue_would_carry_them(): void
    {
        $copy = CustomEventData::fromArray([
            ...$this->data()->toArray(),
            'type' => 'custom',
            'occurred_at' => '2026-09-29 10:00:00',
        ]);

        $this->assertSame(EventType::Custom, $copy->type);
        $this->assertSame('2026-09-29 10:00:00', $copy->occurredAt->toDateTimeString());
    }

    public function test_with_visitor_id_returns_a_copy(): void
    {
        $data = $this->data();
        $copy = $data->withVisitorId('adopted');

        $this->assertSame('visitor-1', $data->visitorId);
        $this->assertSame('adopted', $copy->visitorId);
        $this->assertSame('purchase', $copy->name);
    }

    public function test_a_page_view_type_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->data(EventType::PageView);
    }
}
