<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\QueryApi;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Facades\Stats;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\RollupRunner;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;

class StatsDownloadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 12:30:00');
    }

    private function download(string $visitor, string $time, string $path, ?string $extension = 'pdf', ?string $host = null): void
    {
        AnalyticsEvent::factory()->download($path, $extension, $host)->create(['visitor_id' => $visitor, 'occurred_at' => Carbon::parse($time)]);
    }

    private function rollUp(): void
    {
        app(RollupRunner::class)->run(Carbon::now()->toImmutable(), Carbon::parse('2026-03-08 00:00:00')->toImmutable());
    }

    private function report()
    {
        return Stats::between(Carbon::parse('2026-03-08'), Carbon::parse('2026-03-10'));
    }

    public function test_downloads_rank_by_events_with_exact_users_for_files_on_and_off_the_site(): void
    {
        $this->download('v1', '2026-03-08 10:00:00', '/files/a.pdf');
        $this->download('v1', '2026-03-09 10:00:00', '/files/a.pdf');
        $this->download('v2', '2026-03-09 11:00:00', '/files/a.pdf');
        $this->download('v3', '2026-03-09 12:00:00', '/a/report.zip', 'zip', 'cdn.example.org');
        $this->download('v3', '2026-03-09 13:00:00', '/a/report.zip', 'zip', 'cdn.example.org');
        $this->download('v4', '2026-03-09 14:00:00', '/a/report.zip', 'zip');
        $this->rollUp();

        $rows = $this->report()->top(RollupDimension::Download);

        $this->assertSame(['/files/a.pdf', 'cdn.example.org/a/report.zip', '/a/report.zip'], array_map(fn ($row) => $row->value, $rows));
        $this->assertSame([3, 2, 1], array_map(fn ($row) => $row->events, $rows));
        $this->assertSame([2, 1, 1], array_map(fn ($row) => $row->users, $rows));
        $this->assertTrue($rows[0]->usersExact);
    }

    public function test_file_extensions_rank_by_events_with_exact_users(): void
    {
        $this->download('v1', '2026-03-08 10:00:00', '/files/a.pdf');
        $this->download('v1', '2026-03-09 10:00:00', '/files/b.pdf');
        $this->download('v2', '2026-03-09 11:00:00', '/files/a.pdf');
        $this->download('v3', '2026-03-09 12:00:00', '/files/c.zip', 'zip');
        $this->rollUp();

        $rows = $this->report()->top(RollupDimension::FileExtension);

        $this->assertSame(['pdf', 'zip'], array_map(fn ($row) => $row->value, $rows));
        $this->assertSame([3, 1], array_map(fn ($row) => $row->events, $rows));
        $this->assertSame([2, 1], array_map(fn ($row) => $row->users, $rows));
    }

    public function test_pruned_days_make_download_rows_inexact(): void
    {
        $this->download('v1', '2026-03-08 10:00:00', '/files/a.pdf');
        $this->download('v2', '2026-03-09 10:00:00', '/files/a.pdf');
        $this->rollUp();
        AnalyticsEvent::query()->where('occurred_at', '<', Carbon::parse('2026-03-09'))->delete();

        $download = $this->report()->top(RollupDimension::Download)[0];
        $extension = $this->report()->top(RollupDimension::FileExtension)[0];

        $this->assertSame(2, $download->events);
        $this->assertSame(2, $download->users);
        $this->assertFalse($download->usersExact);
        $this->assertFalse($extension->usersExact);
    }
}
