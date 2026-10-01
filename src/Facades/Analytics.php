<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Facades;

use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\AnalyticsTracker;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void track(string $name, array<array-key, mixed> $properties = [])
 * @method static void goal(string $name, ?float $value = null, array<array-key, mixed> $properties = [])
 * @method static AnalyticsTracker for(?string $visitorId)
 *
 * @see AnalyticsTracker
 */
class Analytics extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AnalyticsTracker::class;
    }
}
