<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Rollup;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsRollup;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\RollupBuilder;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;

class StatusRollupTest extends TestCase
{
    private function pageView(string $visitor, string $path, ?int $status, bool $bot = false): void
    {
        AnalyticsEvent::factory()->create([
            'visitor_id' => $visitor,
            'path' => $path,
            'status' => $status,
            'is_bot' => $bot,
            'occurred_at' => Carbon::parse('2026-03-02 10:05:00'),
        ]);
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

    public function test_every_status_gets_page_views_and_visitors(): void
    {
        $this->pageView('v1', '/a', 200);
        $this->pageView('v1', '/b', 200);
        $this->pageView('v2', '/c', 200);
        $this->pageView('v2', '/missing', 404);
        $this->pageView('v3', '/broken', 500);

        $this->build();

        $ok = $this->row(RollupDimension::Status, '200');

        $this->assertSame(3, $ok->page_views);
        $this->assertSame(2, $ok->visitors);
        $this->assertSame(1, $this->row(RollupDimension::Status, '404')->page_views);
        $this->assertSame(1, $this->row(RollupDimension::Status, '500')->visitors);
    }

    public function test_error_paths_are_stored_with_their_status_and_only_for_errors(): void
    {
        $this->pageView('v1', '/missing', 404);
        $this->pageView('v2', '/missing', 404);
        $this->pageView('v2', '/missing', 404);
        $this->pageView('v3', '/checkout', 500);
        $this->pageView('v4', '/fine', 200);

        $this->build();

        $missing = $this->row(RollupDimension::ErrorPath, '404 /missing');

        $this->assertSame(3, $missing->page_views);
        $this->assertSame(2, $missing->visitors);
        $this->assertSame(0, $missing->sessions);
        $this->assertSame(1, $this->row(RollupDimension::ErrorPath, '500 /checkout')->page_views);
        $this->assertSame(2, AnalyticsRollup::query()->where('dimension', RollupDimension::ErrorPath)->count());
    }

    public function test_page_views_without_a_status_add_no_status_or_error_rows(): void
    {
        $this->pageView('v1', '/spa-page', null);

        $this->build();

        $this->assertSame(0, AnalyticsRollup::query()->whereIn('dimension', [RollupDimension::Status, RollupDimension::ErrorPath])->count());
        $this->assertSame(1, $this->row(RollupDimension::Total, '')->page_views);
    }

    public function test_any_status_of_400_or_more_gets_an_error_path_row(): void
    {
        $this->pageView('v1', '/gone', 410);
        $this->pageView('v1', '/teapot', 418);

        $this->build();

        $this->assertNotNull($this->row(RollupDimension::ErrorPath, '410 /gone'));
        $this->assertNotNull($this->row(RollupDimension::ErrorPath, '418 /teapot'));
    }

    public function test_error_paths_that_differ_only_in_case_stay_separate(): void
    {
        $this->pageView('v1', '/About', 404);
        $this->pageView('v2', '/about', 404);
        $this->pageView('v3', '/about', 404);

        $this->build();

        $this->assertSame(1, $this->row(RollupDimension::ErrorPath, '404 /About')->page_views);
        $this->assertSame(2, $this->row(RollupDimension::ErrorPath, '404 /about')->page_views);
    }

    public function test_bots_are_left_out(): void
    {
        $this->pageView('v1', '/missing', 404, bot: true);

        $this->build();

        $this->assertNull($this->row(RollupDimension::Status, '404'));
        $this->assertNull($this->row(RollupDimension::ErrorPath, '404 /missing'));
    }
}
