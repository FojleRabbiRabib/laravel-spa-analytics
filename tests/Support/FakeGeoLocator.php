<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Support;

use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\GeoLocator;
use Illuminate\Http\Request;

final class FakeGeoLocator implements GeoLocator
{
    public function country(Request $request): ?string
    {
        return 'NZ';
    }
}
