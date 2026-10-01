<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Unit\Enums;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;
use PHPUnit\Framework\TestCase;

class RollupPeriodTest extends TestCase
{
    public function test_helpers(): void
    {
        $this->assertTrue(RollupPeriod::Hour->is(RollupPeriod::Hour));
        $this->assertFalse(RollupPeriod::Hour->is(RollupPeriod::Day));
        $this->assertSame('Hour', RollupPeriod::Hour->label());
        $this->assertSame('Day', RollupPeriod::Day->label());
        $this->assertSame('blue', RollupPeriod::Hour->color());
        $this->assertSame('green', RollupPeriod::Day->color());
        $this->assertSame(['hour', 'day'], RollupPeriod::values());
        $this->assertSame(RollupPeriod::Hour, RollupPeriod::default());
        $this->assertSame([['value' => 'hour', 'label' => 'Hour'], ['value' => 'day', 'label' => 'Day']], RollupPeriod::options());
    }

    public function test_start_floors_to_the_bucket(): void
    {
        $at = CarbonImmutable::parse('2026-03-02 14:35:21');

        $this->assertSame('2026-03-02 14:00:00', RollupPeriod::Hour->start($at)->toDateTimeString());
        $this->assertSame('2026-03-02 00:00:00', RollupPeriod::Day->start($at)->toDateTimeString());
    }

    public function test_end_is_the_exclusive_start_of_the_next_bucket(): void
    {
        $start = CarbonImmutable::parse('2026-03-02 23:00:00');

        $this->assertSame('2026-03-03 00:00:00', RollupPeriod::Hour->end($start)->toDateTimeString());
        $this->assertSame('2026-03-03 23:00:00', RollupPeriod::Day->end($start)->toDateTimeString());
    }
}
