<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Query;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;

/**
 * The hours of a range that have not been rolled up yet, worked out in memory from the raw events. It answers the
 * same questions as the stored rollups (sums of a dimension, its values with their sums, the total of each hour), so a
 * report can add it to what the rollup table says. Nothing here is stored.
 */
final readonly class LiveTail
{
    private const METRICS = ['page_views', 'visitors', 'sessions', 'bounces', 'duration_seconds', 'events', 'revenue', 'engaged_seconds'];

    /**
     * @param  array<string, array<string, array<string, int|float>>>  $hours  The rows of each hour, by the start of the hour, as RollupBuilder::preview() returns them.
     */
    public function __construct(public array $hours = []) {}

    /**
     * The total row of each hour, by the start of the hour.
     *
     * @return array<string, array<string, int|string>>
     */
    public function totals(): array
    {
        $totals = [];

        foreach ($this->hours as $start => $rows) {
            $totals[$start] = self::format($rows[RollupDimension::Total->key('')] ?? []);
        }

        return $totals;
    }

    /**
     * The metric sums of every value of one dimension over all the hours, as the stored rows are summed.
     *
     * @return array<string, int|string>
     */
    public function sums(RollupDimension $dimension): array
    {
        $sum = array_fill_keys(self::METRICS, 0);

        foreach ($this->hours as $rows) {
            foreach ($rows as $key => $metrics) {
                if (! RollupDimension::parseKey($key)[0]->is($dimension)) {
                    continue;
                }

                foreach ($metrics as $metric => $amount) {
                    $sum[$metric] += $amount;
                }
            }
        }

        return self::format($sum);
    }

    /**
     * Every value of a dimension with its metric sums over all the hours.
     *
     * @return array<string, array<string, int|string>>
     */
    public function grouped(RollupDimension $dimension): array
    {
        $groups = [];

        foreach ($this->hours as $rows) {
            foreach ($rows as $key => $metrics) {
                [$rowDimension, $value] = RollupDimension::parseKey($key);

                if (! $rowDimension->is($dimension)) {
                    continue;
                }

                $groups[$value] ??= array_fill_keys(self::METRICS, 0);

                foreach ($metrics as $metric => $amount) {
                    $groups[$value][$metric] += $amount;
                }
            }
        }

        return array_map(self::format(...), $groups);
    }

    /**
     * The metrics of two rows added together; a missing metric counts as zero.
     *
     * @param  array<string, int|float|string>  $first
     * @param  array<string, int|float|string>  $second
     * @return array<string, int|string>
     */
    public static function add(array $first, array $second): array
    {
        $sum = [];

        foreach (self::METRICS as $metric) {
            $sum[$metric] = (float) ($first[$metric] ?? 0) + (float) ($second[$metric] ?? 0);
        }

        return self::format($sum);
    }

    /**
     * @param  array<string, int|float|string>  $row
     * @return array<string, int|string>
     */
    private static function format(array $row): array
    {
        $values = [];

        foreach (self::METRICS as $metric) {
            $values[$metric] = $metric === 'revenue'
                ? number_format(round((float) ($row[$metric] ?? 0), 2), 2, '.', '')
                : (int) ($row[$metric] ?? 0);
        }

        return $values;
    }
}
