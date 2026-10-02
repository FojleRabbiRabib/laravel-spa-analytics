<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Unit\Enums;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\FunnelStepType;
use PHPUnit\Framework\TestCase;

class FunnelStepTypeTest extends TestCase
{
    public function test_helpers(): void
    {
        $this->assertTrue(FunnelStepType::Goal->is(FunnelStepType::Goal));
        $this->assertFalse(FunnelStepType::Goal->is(FunnelStepType::Event));
        $this->assertSame('Path prefix', FunnelStepType::PathPrefix->label());
        $this->assertSame('blue', FunnelStepType::Path->color());
        $this->assertSame('blue', FunnelStepType::PathPrefix->color());
        $this->assertSame('purple', FunnelStepType::Event->color());
        $this->assertSame('green', FunnelStepType::Goal->color());
        $this->assertSame(['path', 'path_prefix', 'event', 'goal'], FunnelStepType::values());
        $this->assertSame(FunnelStepType::Path, FunnelStepType::default());
        $this->assertSame(
            [['value' => 'path', 'label' => 'Path'], ['value' => 'path_prefix', 'label' => 'Path prefix'], ['value' => 'event', 'label' => 'Event'], ['value' => 'goal', 'label' => 'Goal']],
            FunnelStepType::options(),
        );
    }

    public function test_each_type_reads_one_kind_of_raw_event(): void
    {
        $this->assertSame(EventType::PageView, FunnelStepType::Path->eventType());
        $this->assertSame(EventType::PageView, FunnelStepType::PathPrefix->eventType());
        $this->assertSame(EventType::Custom, FunnelStepType::Event->eventType());
        $this->assertSame(EventType::Goal, FunnelStepType::Goal->eventType());
    }
}
