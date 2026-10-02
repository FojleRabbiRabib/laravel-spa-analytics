<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\DeviceDetector;
use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\GeoLocator;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\AudienceData;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\DeviceInfo;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\DeviceType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AudienceResolver
{
    public function __construct(
        private readonly DeviceDetector $devices,
        private readonly GeoLocator $geo,
    ) {}

    /**
     * Device and country for the session; a failing detector or locator costs only its own values, never the page view.
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
            $country = $this->geo->country($request);
        } catch (\Throwable $e) {
            $this->logFailure($e);
            $country = null;
        }

        return new AudienceData(
            $device->deviceType,
            $device->os === null ? null : mb_substr($device->os, 0, 32),
            $device->browser === null ? null : mb_substr($device->browser, 0, 32),
            $device->browserVersion === null ? null : mb_substr($device->browserVersion, 0, 16),
            $country === null ? null : mb_substr($country, 0, 2),
        );
    }

    private function logFailure(\Throwable $e): void
    {
        Log::warning('spa-analytics: audience lookup failed', ['exception' => $e::class, 'code' => $e->getCode()]);
    }
}
