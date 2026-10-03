<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\QueryApi;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Facades\Stats;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\RollupRunner;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;

class StatsLanguageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 12:30:00');
    }

    private function pageView(string $visitor, string $language, string $time = '2026-03-08 10:00:00'): void
    {
        AnalyticsEvent::factory()->create([
            'visitor_id' => $visitor,
            'language' => $language,
            'occurred_at' => Carbon::parse($time),
        ]);
    }

    private function rollUp(): void
    {
        app(RollupRunner::class)->run(Carbon::now()->toImmutable(), Carbon::parse('2026-03-08 00:00:00')->toImmutable());
    }

    private function report()
    {
        return Stats::between(Carbon::parse('2026-03-08'), Carbon::parse('2026-03-10'));
    }

    public function test_languages_rank_by_page_views_with_exact_users_across_case_variants(): void
    {
        $this->pageView('v1', 'en-US');
        $this->pageView('v1', 'en-us', '2026-03-09 10:00:00');
        $this->pageView('v2', 'en-US');
        $this->pageView('v3', 'en-us');
        $this->pageView('v4', 'de');
        $this->pageView('v4', 'de', '2026-03-09 11:00:00');
        $this->pageView('v4', 'de', '2026-03-09 12:00:00');
        $this->rollUp();

        $rows = $this->report()->top(RollupDimension::Language);

        $this->assertSame(['en-us', 'de'], array_map(fn ($row) => $row->value, $rows));
        $this->assertSame([4, 3], array_map(fn ($row) => $row->pageViews, $rows));
        $this->assertSame([3, 1], array_map(fn ($row) => $row->users, $rows));
        $this->assertTrue($rows[0]->usersExact);
    }

    public function test_pruned_days_make_a_language_row_inexact(): void
    {
        $this->pageView('v1', 'fr');
        $this->pageView('v2', 'fr', '2026-03-09 10:00:00');
        $this->rollUp();
        AnalyticsEvent::query()->where('occurred_at', '<', Carbon::parse('2026-03-09'))->delete();

        $row = $this->report()->top(RollupDimension::Language)[0];

        $this->assertSame(2, $row->users);
        $this->assertFalse($row->usersExact);
    }
}
