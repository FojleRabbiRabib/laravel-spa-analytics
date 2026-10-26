<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Contracts;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\GeoLocation;
use Illuminate\Http\Request;

interface GeoLookup
{
    /**
     * Where the visitor is, as one answer so the country and the region always come from the same source. A value that
     * is not known is null; nothing here has to be stored, and an IP address never needs to leave the request.
     */
    public function locate(Request $request): GeoLocation;
}
