<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\GeoLocator;
use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\GeoLookup;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\GeoLocation;
use Illuminate\Http\Request;

class HeaderGeoLocator implements GeoLocator, GeoLookup
{
    private const PLACEHOLDERS = ['XX', 'ZZ'];

    public function __construct(
        private readonly ?string $header,
        private readonly ?string $regionHeader = null,
    ) {}

    /**
     * Read the country a CDN or proxy put in the configured header; placeholders and malformed values count as unknown.
     */
    public function country(Request $request): ?string
    {
        if ($this->header === null || $this->header === '') {
            return null;
        }

        $value = strtoupper(trim((string) $request->headers->get($this->header)));

        if (preg_match('/^[A-Z]{2}$/D', $value) !== 1 || in_array($value, self::PLACEHOLDERS, true)) {
            return null;
        }

        return $value;
    }

    /**
     * The country, and the region from its own header as a full ISO 3166-2 code (the header holds only the part after
     * the dash, which alone is ambiguous across countries). A region without a known country is dropped.
     */
    public function locate(Request $request): GeoLocation
    {
        $country = $this->country($request);

        return new GeoLocation($country, $country === null ? null : $this->region($request, $country));
    }

    private function region(Request $request, string $country): ?string
    {
        if ($this->regionHeader === null || $this->regionHeader === '') {
            return null;
        }

        $value = strtoupper(trim((string) $request->headers->get($this->regionHeader)));

        if (str_starts_with($value, $country.'-')) {
            $value = substr($value, 3);
        }

        return preg_match('/^[A-Z0-9]{1,3}$/D', $value) === 1 ? $country.'-'.$value : null;
    }
}
