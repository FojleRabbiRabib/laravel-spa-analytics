<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Unit\Enums;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use PHPUnit\Framework\TestCase;

class EventTypeTest extends TestCase
{
    public function test_helpers(): void
    {
        $this->assertTrue(EventType::PageView->is(EventType::PageView));
        $this->assertFalse(EventType::PageView->is(EventType::Goal));
        $this->assertSame('Page view', EventType::PageView->label());
        $this->assertSame('Custom event', EventType::Custom->label());
        $this->assertSame('Goal', EventType::Goal->label());
        $this->assertSame('blue', EventType::PageView->color());
        $this->assertSame('purple', EventType::Custom->color());
        $this->assertSame('green', EventType::Goal->color());
        $this->assertSame(['page_view', 'custom', 'goal'], EventType::values());
        $this->assertSame(EventType::PageView, EventType::default());
        $this->assertSame([
            ['value' => 'page_view', 'label' => 'Page view'],
            ['value' => 'custom', 'label' => 'Custom event'],
            ['value' => 'goal', 'label' => 'Goal'],
        ], EventType::options());
    }

    public function test_coerce_accepts_a_case_or_its_backing_string(): void
    {
        $this->assertSame(EventType::Goal, EventType::coerce(EventType::Goal));
        $this->assertSame(EventType::Custom, EventType::coerce('custom'));
    }
}
