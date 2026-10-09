<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Query;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\PlannedBucket;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\RangePlan;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsRollup;
use Illuminate\Database\Query\Builder;

class RollupReader
{
    private const METRICS = ['page_views', 'visitors', 'sessions', 'bounces', 'duration_seconds', 'events', 'revenue', 'engaged_seconds'];

    /**
     * Check the planned buckets against the rollup table. A planned day without a day row is read from its hours
     * instead, and an hour without a row marks the result incomplete.
     *
     * @return array{buckets: array<int, PlannedBucket>, incomplete: bool}
     */
    public function resolve(RangePlan $plan): array
    {
        $days = array_values(array_filter($plan->buckets, fn (PlannedBucket $bucket): bool => $bucket->period->is(RollupPeriod::Day)));
        $present = $this->existing(RollupPeriod::Day, $days);
        $buckets = [];

        foreach ($plan->buckets as $bucket) {
            if (! $bucket->period->is(RollupPeriod::Day) || isset($present[$bucket->start->toDateTimeString()])) {
                $buckets[] = $bucket;

                continue;
            }

            for ($hour = $bucket->start; $hour->lessThan($bucket->end()); $hour = $hour->addHour()) {
                $buckets[] = new PlannedBucket(RollupPeriod::Hour, $hour);
            }
        }

        $hours = array_values(array_filter($buckets, fn (PlannedBucket $bucket): bool => $bucket->period->is(RollupPeriod::Hour)));
        $presentHours = $this->existing(RollupPeriod::Hour, $hours);

        $incomplete = count($presentHours) < count($hours);

        return ['buckets' => $buckets, 'incomplete' => $incomplete];
    }

    /**
     * The metric sums of one dimension value over the buckets.
     *
     * @param  array<int, PlannedBucket>  $buckets
     * @return array<string, int|string>
     */
    public function sums(array $buckets, RollupDimension $dimension, ?string $value = null): array
    {
        $query = $this->query($buckets, $dimension, $value)
            ->selectRaw(implode(', ', array_map(fn (string $metric): string => 'coalesce(sum('.$metric.'), 0) as '.$metric, self::METRICS)));

        $row = (array) ($query->first() ?? []);

        return $this->normalise($row);
    }

    /**
     * The values of one dimension with their metric sums over the buckets, best first by the ordering metric.
     *
     * Rows are grouped by value hash, so values that differ only in case or trailing spaces stay apart on every engine.
     *
     * @param  array<int, PlannedBucket>  $buckets
     * @param  ?array<int, string>  $values  Limits the rows to these values.
     * @return array<int, array<string, int|string>>
     */
    public function grouped(array $buckets, RollupDimension $dimension, string $orderBy, ?int $limit = null, ?array $values = null): array
    {
        $query = $this->query($buckets, $dimension, null)
            ->groupBy('value_hash')
            ->selectRaw('max(value) as value, '.implode(', ', array_map(fn (string $metric): string => 'coalesce(sum('.$metric.'), 0) as '.$metric, self::METRICS)))
            ->orderByRaw('sum('.$orderBy.') desc')
            ->orderByRaw('max(value) asc');

        if ($values !== null) {
            $query->whereIn('value_hash', array_map(sha1(...), $values));
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        return array_map(fn (object $row): array => ['value' => (string) $row->value, ...$this->normalise((array) $row)], $query->get()->all());
    }

    /**
     * Every hour or day total row between the two moments, keyed by its start.
     *
     * @return array<string, array<string, int|string>>
     */
    public function totalsBetween(RollupPeriod $period, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = AnalyticsRollup::query()
            ->toBase()
            ->where('period', $period)
            ->where('dimension', RollupDimension::Total)
            ->where('bucket_start', '>=', $from->toDateTimeString())
            ->where('bucket_start', '<', $to->toDateTimeString())
            ->get(['bucket_start', ...self::METRICS]);

        $keyed = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $start = CarbonImmutable::parse((string) $row['bucket_start'], (string) config('app.timezone'))->toDateTimeString();
            unset($row['bucket_start']);
            $keyed[$start] = $this->normalise($row);
        }

        return $keyed;
    }

    /**
     * @param  array<int, PlannedBucket>  $buckets
     */
    private function query(array $buckets, RollupDimension $dimension, ?string $value): Builder
    {
        $query = AnalyticsRollup::query()->toBase()->where('dimension', $dimension);

        if ($value !== null) {
            $query->where('value_hash', sha1($value));
        }

        $query->where(function (Builder $query) use ($buckets): void {
            $matched = false;

            foreach ([RollupPeriod::Day, RollupPeriod::Hour] as $period) {
                $starts = $this->starts($buckets, $period);

                if ($starts !== []) {
                    $matched = true;
                    $query->orWhere(fn (Builder $inner) => $inner->where('period', $period)->whereIn('bucket_start', $starts));
                }
            }

            if (! $matched) {
                $query->whereRaw('1 = 0');
            }
        });

        return $query;
    }

    /**
     * @param  array<int, PlannedBucket>  $buckets
     * @return array<string, true>
     */
    private function existing(RollupPeriod $period, array $buckets): array
    {
        $starts = $this->starts($buckets, $period);

        if ($starts === []) {
            return [];
        }

        $present = [];

        foreach (AnalyticsRollup::query()->toBase()->where('period', $period)->where('dimension', RollupDimension::Total)->whereIn('bucket_start', $starts)->pluck('bucket_start') as $start) {
            $present[CarbonImmutable::parse((string) $start, (string) config('app.timezone'))->toDateTimeString()] = true;
        }

        return $present;
    }

    /**
     * @param  array<int, PlannedBucket>  $buckets
     * @return array<int, string>
     */
    private function starts(array $buckets, RollupPeriod $period): array
    {
        $starts = [];

        foreach ($buckets as $bucket) {
            if ($bucket->period->is($period)) {
                $starts[] = $bucket->start->toDateTimeString();
            }
        }

        return $starts;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, int|string>
     */
    private function normalise(array $row): array
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
