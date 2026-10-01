<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\GeoLocator;
use Illuminate\Http\Request;

class HeaderGeoLocator implements GeoLocator
{
    private const PLACEHOLDERS = ['XX', 'ZZ'];

    public function __construct(private readonly ?string $header) {}

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
}
