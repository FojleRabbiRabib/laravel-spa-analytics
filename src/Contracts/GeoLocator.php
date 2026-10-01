<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Contracts;

use Illuminate\Http\Request;

interface GeoLocator
{
    /**
     * The visitor's ISO 3166-1 alpha-2 country code in uppercase, or null when it is not known.
     */
    public function country(Request $request): ?string;
}
