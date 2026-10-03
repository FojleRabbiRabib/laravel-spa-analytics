<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\QueryApi;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Facades\Stats;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\RollupRunner;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ExactUserScanQueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 12:30:00');
    }

    private function rollUp(): void
    {
        app(RollupRunner::class)->run(Carbon::now()->toImmutable(), Carbon::parse('2026-03-08 00:00:00')->toImmutable());
    }

    /**
     * The user query of a top list, with the SQL of every query it ran.
     *
     * @return array<int, string>
     */
    private function queriesOfTop(RollupDimension $dimension): array
    {
        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        Stats::between(Carbon::parse('2026-03-08'), Carbon::parse('2026-03-10'))->top($dimension);

        return array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'count(distinct')));
    }

    /**
     * How many times the column is compared with a list, however the engine quotes identifiers or prefixes `binary`.
     */
    private function comparisons(string $sql, string $column): int
    {
        return (int) preg_match_all('/\b'.preg_quote($column, '/').'\W{0,2}\s+in\s*\(/i', str_replace(['`', '"'], '', $sql));
    }

    public function test_the_users_of_paths_are_narrowed_with_a_plain_comparison_before_the_exact_one(): void
    {
        AnalyticsEvent::factory()->create(['visitor_id' => 'v1', 'path' => '/a', 'occurred_at' => Carbon::parse('2026-03-08 10:00:00')]);
        $this->rollUp();

        $queries = $this->queriesOfTop(RollupDimension::Path);

        $this->assertCount(1, $queries);
        $this->assertSame(2, $this->comparisons($queries[0], 'e.path'));
    }

    public function test_the_users_of_events_and_goals_are_narrowed_the_same_way(): void
    {
        AnalyticsEvent::factory()->goal()->create(['visitor_id' => 'v1', 'name' => 'signup', 'occurred_at' => Carbon::parse('2026-03-08 10:00:00')]);
        AnalyticsEvent::factory()->custom()->create(['visitor_id' => 'v1', 'name' => 'clicked', 'occurred_at' => Carbon::parse('2026-03-08 10:00:00')]);
        $this->rollUp();

        $this->assertSame(2, $this->comparisons($this->queriesOfTop(RollupDimension::Goal)[0], 'e.name'));
        $this->assertSame(2, $this->comparisons($this->queriesOfTop(RollupDimension::Event)[0], 'e.name'));
    }

    public function test_the_users_of_error_paths_and_downloads_are_narrowed_the_same_way(): void
    {
        AnalyticsEvent::factory()->create(['visitor_id' => 'v1', 'path' => '/missing', 'status' => 404, 'occurred_at' => Carbon::parse('2026-03-08 10:00:00')]);
        AnalyticsEvent::factory()->download('/files/a.pdf')->create(['visitor_id' => 'v1', 'occurred_at' => Carbon::parse('2026-03-08 10:00:00')]);
        $this->rollUp();

        $this->assertSame(2, $this->comparisons($this->queriesOfTop(RollupDimension::ErrorPath)[0], 'e.path'));
        $this->assertSame(2, $this->comparisons($this->queriesOfTop(RollupDimension::Download)[0], 'e.target_path'));
    }

    public function test_the_exact_numbers_do_not_change(): void
    {
        foreach ([['v1', '/About'], ['v2', '/about'], ['v3', '/about']] as [$visitor, $path]) {
            AnalyticsEvent::factory()->create(['visitor_id' => $visitor, 'path' => $path, 'occurred_at' => Carbon::parse('2026-03-08 10:00:00')]);
        }

        $this->rollUp();

        $rows = Stats::between(Carbon::parse('2026-03-08'), Carbon::parse('2026-03-10'))->top(RollupDimension::Path);

        $this->assertSame(['/about' => 2, '/About' => 1], collect($rows)->mapWithKeys(fn ($row) => [$row->value => $row->users])->all());
    }
}
