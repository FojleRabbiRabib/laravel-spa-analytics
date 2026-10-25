<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Query;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\Realtime;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\DimensionSettings;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup\RollupBuilder;

class StatsService
{
    public function __construct(
        private readonly RangePlanner $planner,
        private readonly RollupCoverage $coverage,
        private readonly RollupReader $reader,
        private readonly UsersCounter $users,
        private readonly RealtimeReader $realtime,
        private readonly FunnelCounter $funnels,
        private readonly DimensionSettings $settings,
        private readonly RollupBuilder $builder,
    ) {}

    /**
     * A report over the range, read in the app timezone and snapped outward to whole hours.
     */
    public function between(CarbonInterface $from, CarbonInterface $to): StatsReport
    {
        $timezone = (string) config('app.timezone');

        return new StatsReport(
            CarbonImmutable::instance($from)->setTimezone($timezone),
            CarbonImmutable::instance($to)->setTimezone($timezone),
            $this->planner,
            $this->coverage,
            $this->reader,
            $this->users,
            $this->funnels,
            $this->settings,
            $this->builder,
            (bool) config('spa-analytics.stats.live'),
        );
    }

    /**
     * A report over the complete days before today, which is left out.
     */
    public function lastDays(int $days): StatsReport
    {
        $today = CarbonImmutable::now((string) config('app.timezone'))->startOfDay();

        return $this->between($today->subDays(max(1, $days)), $today);
    }

    /**
     * Who is on the site now, from the raw events of the last few minutes.
     */
    public function realtime(): Realtime
    {
        return $this->realtime->read();
    }
}
