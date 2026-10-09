<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Console;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupPeriod;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\DimensionSettings;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\RollupRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RollupCommand extends Command
{
    protected $signature = 'spa-analytics:rollup
        {--since= : Recompute from this date or time instead of the recent lookback hours}
        {--period=both : hour, day or both}
        {--purge-disabled : Delete the stored rows of the dimensions in rollups.disabled_dimensions instead of rolling up}';

    protected $description = 'Summarise raw analytics events and sessions into hourly and daily rollups';

    /**
     * Recompute the rollups under a lock so two runs never write the same buckets at once.
     */
    public function handle(RollupRunner $runner, DimensionSettings $settings): int
    {
        if ($this->option('purge-disabled') && ($this->option('since') || $this->option('period') !== 'both')) {
            $this->components->error('The purge-disabled option deletes rows of whole dimensions and cannot be combined with since or period.');

            return self::FAILURE;
        }

        foreach ($settings->problems() as $problem) {
            $this->components->warn("rollups.disabled_dimensions: {$problem}; ignored.");
        }

        $periods = match ($this->option('period')) {
            'hour' => [RollupPeriod::Hour],
            'day' => [RollupPeriod::Day],
            'both' => [RollupPeriod::Hour, RollupPeriod::Day],
            default => null,
        };

        if ($periods === null) {
            $this->components->error('The period must be hour, day or both.');

            return self::FAILURE;
        }

        try {
            $since = $this->option('since') ? CarbonImmutable::parse((string) $this->option('since')) : null;
        } catch (\Throwable) {
            $this->components->error('The since option is not a valid date.');

            return self::FAILURE;
        }

        $lock = Cache::lock('spa-analytics:rollup', 3600);

        if (! $lock->get()) {
            $this->components->warn('Another rollup is already running; skipped.');

            return self::SUCCESS;
        }

        if ($this->option('purge-disabled')) {
            try {
                $deleted = $runner->purgeDisabled();
            } finally {
                $lock->release();
            }

            $this->components->info("Deleted {$deleted} rollup rows of disabled dimensions.");

            return self::SUCCESS;
        }

        try {
            $result = $runner->run(CarbonImmutable::now(), $since, $periods);
        } finally {
            $lock->release();
        }

        if ($result['skippedDays'] > 0) {
            $this->components->warn("{$result['skippedDays']} days before the retention cutoff ({$result['cutoff']?->toDateString()}) already have rollups and were left as they are.");
        }

        $this->components->info("Rolled up {$result['hours']} hourly and {$result['days']} daily buckets.");

        return self::SUCCESS;
    }
}
