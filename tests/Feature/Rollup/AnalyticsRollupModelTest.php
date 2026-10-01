<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Rollup;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsRollup;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

class AnalyticsRollupModelTest extends TestCase
{
    public function test_the_factory_persists_a_rollup_with_enum_casts(): void
    {
        $rollup = AnalyticsRollup::factory()->create([
            'period' => RollupPeriod::Day,
            'dimension' => RollupDimension::Country,
            'value' => 'BD',
            'revenue' => 12.5,
        ])->fresh();

        $this->assertSame(RollupPeriod::Day, $rollup->period);
        $this->assertSame(RollupDimension::Country, $rollup->dimension);
        $this->assertSame('BD', $rollup->value);
        $this->assertSame('12.50', $rollup->revenue);
        $this->assertSame(0, $rollup->bounces);
    }

    public function test_the_total_dimension_uses_an_empty_value(): void
    {
        $this->assertSame('', AnalyticsRollup::factory()->create()->fresh()->value);
    }

    public function test_a_bucket_dimension_and_value_exist_only_once(): void
    {
        $bucket = Carbon::parse('2026-03-02 10:00:00');

        AnalyticsRollup::factory()->create(['bucket_start' => $bucket, 'dimension' => RollupDimension::Path, 'value' => '/a']);

        $this->expectException(QueryException::class);

        AnalyticsRollup::factory()->create(['bucket_start' => $bucket, 'dimension' => RollupDimension::Path, 'value' => '/a']);
    }

    public function test_the_same_value_may_exist_in_another_period_or_bucket(): void
    {
        $bucket = Carbon::parse('2026-03-02 00:00:00');

        AnalyticsRollup::factory()->create(['period' => RollupPeriod::Hour, 'bucket_start' => $bucket]);
        AnalyticsRollup::factory()->create(['period' => RollupPeriod::Day, 'bucket_start' => $bucket]);
        AnalyticsRollup::factory()->create(['period' => RollupPeriod::Day, 'bucket_start' => $bucket->copy()->addDay()]);

        $this->assertSame(3, AnalyticsRollup::query()->count());
    }
}
