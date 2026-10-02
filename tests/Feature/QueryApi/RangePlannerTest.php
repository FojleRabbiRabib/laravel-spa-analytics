<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\QueryApi;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Query\RangePlanner;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;

class RangePlannerTest extends TestCase
{
    private function at(string $moment): CarbonImmutable
    {
        return CarbonImmutable::parse($moment, 'UTC');
    }

    /**
     * @return array<int, string>
     */
    private function summary(array $buckets): array
    {
        return array_map(fn ($bucket): string => $bucket->period->value.' '.$bucket->start->toDateTimeString(), $buckets);
    }

    /**
     * The hours a plan covers, expanding day buckets, so overlaps and holes are easy to see.
     *
     * @return array<int, string>
     */
    private function hoursCovered(array $buckets): array
    {
        $hours = [];

        foreach ($buckets as $bucket) {
            for ($start = $bucket->start; $start->lessThan($bucket->period->end($bucket->start)); $start = $start->addHour()) {
                $hours[] = $start->toDateTimeString();
            }
        }

        return $hours;
    }

    public function test_a_range_inside_one_day_uses_hour_buckets(): void
    {
        $plan = (new RangePlanner)->plan($this->at('2026-03-02 10:00:00'), $this->at('2026-03-02 14:00:00'), $this->at('2100-01-01 00:00:00'));

        $this->assertSame([
            'hour 2026-03-02 10:00:00', 'hour 2026-03-02 11:00:00', 'hour 2026-03-02 12:00:00', 'hour 2026-03-02 13:00:00',
        ], $this->summary($plan->buckets));
    }

    public function test_a_range_across_midnight_uses_hours_on_both_sides(): void
    {
        $plan = (new RangePlanner)->plan($this->at('2026-03-02 22:00:00'), $this->at('2026-03-03 02:00:00'), $this->at('2100-01-01 00:00:00'));

        $this->assertSame([
            'hour 2026-03-02 22:00:00', 'hour 2026-03-02 23:00:00', 'hour 2026-03-03 00:00:00', 'hour 2026-03-03 01:00:00',
        ], $this->summary($plan->buckets));
    }

    public function test_whole_days_use_day_buckets(): void
    {
        $plan = (new RangePlanner)->plan($this->at('2026-03-02 00:00:00'), $this->at('2026-03-04 00:00:00'), $this->at('2100-01-01 00:00:00'));

        $this->assertSame(['day 2026-03-02 00:00:00', 'day 2026-03-03 00:00:00'], $this->summary($plan->buckets));
    }

    public function test_mid_day_to_mid_day_mixes_hours_and_days_without_overlap_or_holes(): void
    {
        $plan = (new RangePlanner)->plan($this->at('2026-03-02 10:30:00'), $this->at('2026-03-05 14:10:00'), $this->at('2100-01-01 00:00:00'));

        $hours = collect($plan->buckets)->filter(fn ($b) => $b->period->is(RollupPeriod::Hour))->count();
        $days = collect($plan->buckets)->filter(fn ($b) => $b->period->is(RollupPeriod::Day))->count();

        $this->assertSame(14 + 15, $hours);
        $this->assertSame(2, $days);

        $covered = $this->hoursCovered($plan->buckets);
        $this->assertSame($covered, array_values(array_unique($covered)));
        $this->assertCount(77, $covered);
        $this->assertSame('2026-03-02 10:00:00', $covered[0]);
        $this->assertSame('2026-03-05 14:00:00', end($covered));
    }

    public function test_the_range_is_snapped_outward_to_whole_hours(): void
    {
        $plan = (new RangePlanner)->plan($this->at('2026-03-02 10:30:00'), $this->at('2026-03-02 12:10:00'), $this->at('2100-01-01 00:00:00'));

        $this->assertSame('2026-03-02 10:00:00', $plan->from->toDateTimeString());
        $this->assertSame('2026-03-02 13:00:00', $plan->to->toDateTimeString());
        $this->assertCount(3, $plan->buckets);
    }

    public function test_an_end_exactly_on_the_hour_is_not_extended(): void
    {
        $plan = (new RangePlanner)->plan($this->at('2026-03-02 10:00:00'), $this->at('2026-03-02 12:00:00'), $this->at('2100-01-01 00:00:00'));

        $this->assertSame('2026-03-02 12:00:00', $plan->to->toDateTimeString());
    }

    public function test_the_range_is_read_in_the_app_timezone(): void
    {
        config()->set('app.timezone', 'Asia/Dhaka');

        $plan = (new RangePlanner)->plan($this->at('2026-03-01 18:00:00'), $this->at('2026-03-02 18:00:00'), $this->at('2100-01-01 00:00:00'));

        $this->assertSame('2026-03-02 00:00:00', $plan->from->toDateTimeString());
        $this->assertSame('Asia/Dhaka', $plan->from->getTimezone()->getName());
        $this->assertSame(['day 2026-03-02 00:00:00'], $this->summary($plan->buckets));
    }

    public function test_an_empty_or_reversed_range_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new RangePlanner)->plan($this->at('2026-03-02 10:00:00'), $this->at('2026-03-02 10:00:00'), $this->at('2100-01-01 00:00:00'));
    }

    public function test_a_reversed_range_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new RangePlanner)->plan($this->at('2026-03-03 10:00:00'), $this->at('2026-03-02 10:00:00'), $this->at('2100-01-01 00:00:00'));
    }

    public function test_the_range_stops_where_the_rollups_stop(): void
    {
        $plan = (new RangePlanner)->plan($this->at('2026-03-02 00:00:00'), $this->at('2026-03-04 00:00:00'), $this->at('2026-03-03 06:00:00'));

        $this->assertSame('2026-03-03 06:00:00', $plan->through->toDateTimeString());
        $this->assertSame('2026-03-03 06:00:00', $plan->effectiveTo->toDateTimeString());
        $this->assertSame('day 2026-03-02 00:00:00', $this->summary($plan->buckets)[0]);
        $this->assertCount(1 + 6, $plan->buckets);
        $this->assertSame('hour 2026-03-03 05:00:00', $this->summary($plan->buckets)[6]);
    }

    public function test_a_range_wholly_after_the_rollups_has_nothing_to_read(): void
    {
        $plan = (new RangePlanner)->plan($this->at('2026-03-05 00:00:00'), $this->at('2026-03-06 00:00:00'), $this->at('2026-03-03 06:00:00'));

        $this->assertSame([], $plan->buckets);
        $this->assertTrue($plan->effectiveTo->lessThanOrEqualTo($plan->from));
    }

    public function test_without_any_rollups_there_is_nothing_to_read(): void
    {
        $plan = (new RangePlanner)->plan($this->at('2026-03-02 00:00:00'), $this->at('2026-03-03 00:00:00'), $this->at('1970-01-01 00:00:00'));

        $this->assertSame([], $plan->buckets);
    }
}
