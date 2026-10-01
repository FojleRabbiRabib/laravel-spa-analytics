<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Support;

use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\BotDetector;
use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\DeviceDetector;
use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\EventStore;
use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\GeoLocator;
use Illuminate\Support\ServiceProvider;

/**
 * Stands in for a consuming app that swaps implementations in its own register().
 */
final class OverridingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(BotDetector::class, FakeBotDetector::class);
        $this->app->bind(EventStore::class, FakeEventStore::class);
        $this->app->bind(DeviceDetector::class, FakeDeviceDetector::class);
        $this->app->bind(GeoLocator::class, FakeGeoLocator::class);
    }
}
