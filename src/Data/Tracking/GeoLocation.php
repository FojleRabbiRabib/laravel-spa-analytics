<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking;

final readonly class GeoLocation
{
    /**
     * @param  ?string  $country  ISO 3166-1 alpha-2 code in uppercase.
     * @param  ?string  $region  Full ISO 3166-2 code in uppercase, such as US-CA; only with a country.
     */
    public function __construct(
        public ?string $country = null,
        public ?string $region = null,
    ) {}
}
