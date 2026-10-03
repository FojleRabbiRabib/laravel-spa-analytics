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
        $this->assertSame(['page_view', 'custom', 'goal', 'outbound_click', 'scroll_depth', 'file_download'], EventType::values());
        $this->assertSame('File download', EventType::FileDownload->label());
        $this->assertSame('orange', EventType::FileDownload->color());
        $this->assertSame('Outbound click', EventType::OutboundClick->label());
        $this->assertSame('Scroll depth', EventType::ScrollDepth->label());
        $this->assertSame('orange', EventType::OutboundClick->color());
        $this->assertSame('gray', EventType::ScrollDepth->color());
        $this->assertSame(EventType::PageView, EventType::default());
        $this->assertSame([
            ['value' => 'page_view', 'label' => 'Page view'],
            ['value' => 'custom', 'label' => 'Custom event'],
            ['value' => 'goal', 'label' => 'Goal'],
            ['value' => 'outbound_click', 'label' => 'Outbound click'],
            ['value' => 'scroll_depth', 'label' => 'Scroll depth'],
            ['value' => 'file_download', 'label' => 'File download'],
        ], EventType::options());
    }

    public function test_coerce_accepts_a_case_or_its_backing_string(): void
    {
        $this->assertSame(EventType::Goal, EventType::coerce(EventType::Goal));
        $this->assertSame(EventType::Custom, EventType::coerce('custom'));
    }
}
