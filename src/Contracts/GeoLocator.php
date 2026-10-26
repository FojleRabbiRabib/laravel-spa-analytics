<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Contracts;

use Illuminate\Http\Request;

/**
 * @deprecated Bind a GeoLookup instead, which also gives the region. A GeoLocator you have bound keeps working (its
 *             country is used, with no region) and this contract goes at 1.0.
 */
interface GeoLocator
{
    /**
     * The visitor's ISO 3166-1 alpha-2 country code in uppercase, or null when it is not known.
     */
    public function country(Request $request): ?string;
}
