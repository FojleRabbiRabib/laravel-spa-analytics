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

class LanguageRollupTest extends TestCase
{
    private function pageView(string $visitor, ?string $language, bool $bot = false): void
    {
        AnalyticsEvent::factory()->create([
            'visitor_id' => $visitor,
            'language' => $language,
            'is_bot' => $bot,
            'occurred_at' => Carbon::parse('2026-03-02 10:05:00'),
        ]);
    }

    private function build(): void
    {
        app(RollupBuilder::class)->rollup(RollupPeriod::Hour, CarbonImmutable::parse('2026-03-02 10:00:00'));
    }

    private function row(string $value): ?AnalyticsRollup
    {
        return AnalyticsRollup::query()
            ->where('period', RollupPeriod::Hour)
            ->where('dimension', RollupDimension::Language)
            ->where('value_hash', sha1($value))
            ->first();
    }

    public function test_languages_get_page_views_and_visitors_without_sessions(): void
    {
        $this->pageView('v1', 'en-US');
        $this->pageView('v1', 'en-US');
        $this->pageView('v2', 'en-US');
        $this->pageView('v3', 'de');

        $this->build();

        $english = $this->row('en-us');

        $this->assertSame(3, $english->page_views);
        $this->assertSame(2, $english->visitors);
        $this->assertSame(0, $english->sessions);
        $this->assertSame(1, $this->row('de')->page_views);
    }

    public function test_page_views_without_a_language_add_no_row_and_bots_are_left_out(): void
    {
        $this->pageView('v1', null);
        $this->pageView('v2', 'fr', bot: true);

        $this->build();

        $this->assertSame(0, AnalyticsRollup::query()->where('dimension', RollupDimension::Language)->count());
    }

    public function test_tags_that_differ_only_in_case_share_one_lower_case_row(): void
    {
        $this->pageView('v1', 'en-US');
        $this->pageView('v1', 'en-us');
        $this->pageView('v2', 'en-us');

        $this->build();

        $this->assertNull($this->row('en-US'));
        $this->assertSame(3, $this->row('en-us')->page_views);
        $this->assertSame(2, $this->row('en-us')->visitors);
    }
}
