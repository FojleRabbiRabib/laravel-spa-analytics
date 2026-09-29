<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Unit\Data;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\PageViewData;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ReferrerType;
use PHPUnit\Framework\TestCase;

class PageViewDataTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function attributes(): array
    {
        return [
            'type' => 'page_view',
            'visitor_id' => 'abc',
            'path' => '/pricing',
            'status' => 200,
            'referrer_host' => 'google.com',
            'referrer_type' => 'search',
            'utm_source' => 'news',
            'utm_medium' => null,
            'utm_campaign' => null,
            'utm_term' => null,
            'utm_content' => null,
            'language' => 'en',
            'ip' => '203.0.113.9',
            'user_agent' => 'Mozilla/5.0',
            'is_bot' => false,
            'occurred_at' => '2026-09-29 10:00:00',
        ];
    }

    public function test_from_array_hydrates_enums_and_date(): void
    {
        $data = PageViewData::fromArray($this->attributes());

        $this->assertSame(EventType::PageView, $data->type);
        $this->assertSame(ReferrerType::Search, $data->referrerType);
        $this->assertInstanceOf(CarbonImmutable::class, $data->occurredAt);
        $this->assertSame('/pricing', $data->path);
        $this->assertSame(200, $data->status);
        $this->assertFalse($data->isBot);
    }

    public function test_to_array_flattens_utm_and_keeps_enum_instances(): void
    {
        $array = PageViewData::fromArray($this->attributes())->toArray();

        $this->assertSame(EventType::PageView, $array['type']);
        $this->assertSame(ReferrerType::Search, $array['referrer_type']);
        $this->assertSame('news', $array['utm_source']);
        $this->assertNull($array['utm_medium']);
        $this->assertSame('abc', $array['visitor_id']);
        $this->assertSame('google.com', $array['referrer_host']);
        $this->assertFalse($array['is_bot']);
        $this->assertArrayNotHasKey('utm', $array);
    }

    public function test_with_visitor_id_returns_a_copy_with_only_the_id_changed(): void
    {
        $original = PageViewData::fromArray($this->attributes());
        $copy = $original->withVisitorId('other');

        $this->assertSame('abc', $original->visitorId);
        $this->assertSame('other', $copy->visitorId);
        $this->assertEquals($original->toArray(), [...$copy->toArray(), 'visitor_id' => 'abc']);
    }

    public function test_round_trip_is_stable(): void
    {
        $first = PageViewData::fromArray($this->attributes());
        $second = PageViewData::fromArray($first->toArray());

        $this->assertEquals($first, $second);
    }
}
