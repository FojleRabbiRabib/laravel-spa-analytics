<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\DeviceDetector;
use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\GeoLookup;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\AudienceData;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\DeviceInfo;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\GeoLocation;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\DeviceType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AudienceResolver
{
    public function __construct(
        private readonly DeviceDetector $devices,
        private readonly GeoLookup $geo,
    ) {}

    /**
     * Device, country and region for the session; a failing detector or locator costs only its own values, never the page view.
     */
    public function resolve(Request $request, ?string $userAgent): AudienceData
    {
        try {
            $device = $this->devices->detect($userAgent);
        } catch (\Throwable $e) {
            $this->logFailure($e);
            $device = new DeviceInfo(DeviceType::Unknown, null, null, null);
        }

        try {
            $location = $this->geo->locate($request);
        } catch (\Throwable $e) {
            $this->logFailure($e);
            $location = new GeoLocation;
        }

        return new AudienceData(
            $device->deviceType,
            $device->os === null ? null : mb_substr($device->os, 0, 32),
            $device->browser === null ? null : mb_substr($device->browser, 0, 32),
            $device->browserVersion === null ? null : mb_substr($device->browserVersion, 0, 16),
            $location->country === null ? null : mb_substr($location->country, 0, 2),
            $this->region($location),
        );
    }

    /**
     * The region as a full ISO 3166-2 code of the known country, in uppercase; anything else, from any lookup, is dropped.
     */
    private function region(GeoLocation $location): ?string
    {
        if ($location->country === null || $location->region === null) {
            return null;
        }

        $region = strtoupper(trim($location->region));

        return preg_match('/^[A-Z]{2}-[A-Z0-9]{1,3}$/D', $region) === 1 && str_starts_with($region, strtoupper($location->country).'-')
            ? $region
            : null;
    }

    private function logFailure(\Throwable $e): void
    {
        Log::warning('spa-analytics: audience lookup failed', ['exception' => $e::class, 'code' => $e->getCode()]);
    }
}
