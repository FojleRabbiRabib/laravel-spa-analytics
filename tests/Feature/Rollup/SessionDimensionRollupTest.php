<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Rollup;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\DeviceType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ReferrerType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsRollup;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\RollupBuilder;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;

class SessionDimensionRollupTest extends TestCase
{
    /**
     * Every dimension that describes a session, so a dimension added to SESSION_COLUMNS is checked without a new test.
     *
     * @return array<string, array{string, RollupDimension}>
     */
    public static function sessionDimensions(): array
    {
        $cases = [];

        foreach (RollupDimension::SESSION_COLUMNS as $column => $dimension) {
            $cases[$column] = [$column, $dimension];
        }

        return $cases;
    }

    private function valueFor(string $column): string|ReferrerType|DeviceType
    {
        return match ($column) {
            'referrer_type' => ReferrerType::Search,
            'device_type' => DeviceType::Mobile,
            default => 'sample-'.$column,
        };
    }

    private function build(): void
    {
        app(RollupBuilder::class)->rollup(RollupPeriod::Hour, CarbonImmutable::parse('2026-03-02 10:00:00'));
    }

    private function row(RollupDimension $dimension, string $value): ?AnalyticsRollup
    {
        return AnalyticsRollup::query()
            ->where('period', RollupPeriod::Hour)
            ->where('dimension', $dimension)
            ->where('value_hash', sha1($value))
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function visit(string $visitor, array $attributes, int $pageViews = 2): AnalyticsSession
    {
        $session = AnalyticsSession::factory()->create([
            'visitor_id' => $visitor,
            'started_at' => Carbon::parse('2026-03-02 10:05:00'),
            'last_seen_at' => Carbon::parse('2026-03-02 10:15:00'),
            'page_views' => $pageViews,
            ...$attributes,
        ]);

        for ($index = 0; $index < $pageViews; $index++) {
            AnalyticsEvent::factory()->create([
                'visitor_id' => $visitor,
                'session_id' => $session->id,
                'occurred_at' => Carbon::parse('2026-03-02 10:05:00')->addMinutes($index),
            ]);
        }

        return $session;
    }

    #[DataProvider('sessionDimensions')]
    public function test_every_session_dimension_gets_page_views_visitors_and_session_metrics(string $column, RollupDimension $dimension): void
    {
        $value = $this->valueFor($column);
        $this->visit('v1', [$column => $value]);

        $this->build();

        $row = $this->row($dimension, $value instanceof \BackedEnum ? (string) $value->value : $value);

        $this->assertNotNull($row);
        $this->assertSame(2, $row->page_views);
        $this->assertSame(1, $row->visitors);
        $this->assertSame(1, $row->sessions);
        $this->assertSame(600, $row->duration_seconds);
    }

    public function test_utm_values_that_differ_only_in_case_stay_separate_rows_with_their_own_users(): void
    {
        $this->visit('v1', ['utm_source' => 'Newsletter'], 1);
        $this->visit('v2', ['utm_source' => 'newsletter'], 1);
        $this->visit('v3', ['utm_source' => 'newsletter'], 1);

        $this->build();

        $this->assertSame(1, $this->row(RollupDimension::UtmSource, 'Newsletter')->visitors);
        $this->assertSame(2, $this->row(RollupDimension::UtmSource, 'newsletter')->visitors);
    }

    public function test_a_session_without_utm_values_adds_no_utm_rows(): void
    {
        $this->visit('v1', ['utm_source' => null, 'utm_medium' => null, 'utm_term' => null, 'utm_content' => null, 'utm_campaign' => null]);

        $this->build();

        $this->assertSame(0, AnalyticsRollup::query()->whereIn('dimension', [
            RollupDimension::UtmSource, RollupDimension::UtmMedium, RollupDimension::UtmTerm, RollupDimension::UtmContent, RollupDimension::UtmCampaign,
        ])->count());
    }
}
