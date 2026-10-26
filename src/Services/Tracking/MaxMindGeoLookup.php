<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\GeoLookup;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\GeoLocation;
use FojleRabbiRabib\LaravelSpaAnalytics\Exceptions\GeoDatabaseUnavailable;
use Illuminate\Http\Request;
use MaxMind\Db\Reader;

class MaxMindGeoLookup implements GeoLookup
{
    private ?Reader $reader;

    private ?GeoDatabaseUnavailable $failure = null;

    public function __construct(
        private readonly string $path,
        ?Reader $reader = null,
    ) {
        $this->reader = $reader;
    }

    /**
     * Look the request address up in a local MaxMind GeoLite2 or GeoIP2 database. The address is read in memory only
     * and never stored or sent anywhere. Private and invalid addresses, and addresses not in the database, give an
     * empty result; a Country database has no subdivisions, so it gives no region.
     */
    public function locate(Request $request): GeoLocation
    {
        $ip = $request->ip();

        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return new GeoLocation;
        }

        $record = $this->reader()->get($ip);

        if (! is_array($record)) {
            return new GeoLocation;
        }

        $country = $record['country']['iso_code'] ?? null;

        if (! is_string($country)) {
            return new GeoLocation;
        }

        $subdivision = $record['subdivisions'][0]['iso_code'] ?? null;

        return new GeoLocation($country, is_string($subdivision) ? $country.'-'.$subdivision : null);
    }

    private function reader(): Reader
    {
        if ($this->reader !== null) {
            return $this->reader;
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }

        if (! class_exists(Reader::class)) {
            throw $this->failure = GeoDatabaseUnavailable::libraryMissing();
        }

        try {
            return $this->reader = new Reader($this->path);
        } catch (\Throwable) {
            throw $this->failure = GeoDatabaseUnavailable::unreadable($this->path);
        }
    }
}
