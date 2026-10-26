<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\GeoLocator;
use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\GeoLookup;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\GeoLocation;
use Illuminate\Http\Request;

/**
 * Lets an app's own GeoLocator, written before GeoLookup existed, keep working: its country is used and there is no
 * region.
 */
class GeoLocatorAdapter implements GeoLookup
{
    public function __construct(private readonly GeoLocator $locator) {}

    public function locate(Request $request): GeoLocation
    {
        return new GeoLocation($this->locator->country($request));
    }
}
