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

class DownloadRollupTest extends TestCase
{
    private function download(string $visitor, string $path, ?string $extension = 'pdf', ?string $host = null, bool $bot = false): void
    {
        AnalyticsEvent::factory()->download($path, $extension, $host)->create([
            'visitor_id' => $visitor,
            'occurred_at' => Carbon::parse('2026-03-02 10:15:00'),
            'is_bot' => $bot,
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

    public function test_downloads_are_counted_per_file_with_distinct_visitors(): void
    {
        $this->download('v1', '/files/a.pdf');
        $this->download('v1', '/files/a.pdf');
        $this->download('v2', '/files/a.pdf');
        $this->download('v2', '/files/b.pdf');
        $this->download('bot', '/files/a.pdf', bot: true);

        $this->build();

        $file = $this->row(RollupDimension::Download, '/files/a.pdf');

        $this->assertSame(3, $file->events);
        $this->assertSame(2, $file->visitors);
        $this->assertSame(0, $file->page_views);
        $this->assertSame(1, $this->row(RollupDimension::Download, '/files/b.pdf')->events);
    }

    public function test_a_file_on_another_site_is_stored_with_its_host(): void
    {
        $this->download('v1', '/a/report.zip', 'zip', 'cdn.example.org');
        $this->download('v2', '/a/report.zip', 'zip');

        $this->build();

        $this->assertSame(1, $this->row(RollupDimension::Download, 'cdn.example.org/a/report.zip')->events);
        $this->assertSame(1, $this->row(RollupDimension::Download, '/a/report.zip')->events);
    }

    public function test_extensions_are_counted_and_downloads_without_one_or_a_path_add_no_row(): void
    {
        $this->download('v1', '/files/a.pdf');
        $this->download('v2', '/files/b.pdf');
        $this->download('v2', '/files/c.zip', 'zip');
        $this->download('v3', '/export/data', null);
        AnalyticsEvent::factory()->download('/x', 'csv')->create(['visitor_id' => 'v4', 'target_path' => null, 'occurred_at' => Carbon::parse('2026-03-02 10:20:00')]);

        $this->build();

        $this->assertSame(2, $this->row(RollupDimension::FileExtension, 'pdf')->events);
        $this->assertSame(2, $this->row(RollupDimension::FileExtension, 'pdf')->visitors);
        $this->assertSame(1, $this->row(RollupDimension::FileExtension, 'zip')->events);
        $this->assertSame(1, $this->row(RollupDimension::FileExtension, 'csv')->events);
        $this->assertSame(0, AnalyticsRollup::query()->where('dimension', RollupDimension::FileExtension)->where('value', '')->count());
        $this->assertNull($this->row(RollupDimension::Download, '/x'));
        $this->assertSame(4, AnalyticsRollup::query()->where('dimension', RollupDimension::Download)->count());
    }

    public function test_paths_that_differ_only_in_case_stay_separate_and_totals_ignore_downloads(): void
    {
        $this->download('v1', '/files/Guide.pdf');
        $this->download('v2', '/files/guide.pdf');
        $this->download('v3', '/files/guide.pdf');

        $this->build();

        $this->assertSame(1, $this->row(RollupDimension::Download, '/files/Guide.pdf')->visitors);
        $this->assertSame(2, $this->row(RollupDimension::Download, '/files/guide.pdf')->visitors);
        $this->assertSame(0, $this->row(RollupDimension::Total, '')->events);
    }
}
