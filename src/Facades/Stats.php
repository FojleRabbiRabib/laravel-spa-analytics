<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Facades;

use Carbon\CarbonInterface;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\Realtime;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Query\StatsReport;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Query\StatsService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static StatsReport between(CarbonInterface $from, CarbonInterface $to)
 * @method static StatsReport lastDays(int $days)
 * @method static Realtime realtime()
 *
 * @see StatsService
 */
class Stats extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return StatsService::class;
    }
}
