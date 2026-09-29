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
        $this->assertSame('Page view', EventType::PageView->label());
        $this->assertSame('blue', EventType::PageView->color());
        $this->assertSame(['page_view'], EventType::values());
        $this->assertSame(EventType::PageView, EventType::default());
        $this->assertSame([['value' => 'page_view', 'label' => 'Page view']], EventType::options());
    }
}
