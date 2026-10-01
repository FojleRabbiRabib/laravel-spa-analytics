<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\DeviceDetector;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\DeviceInfo;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\DeviceType;

class PatternDeviceDetector implements DeviceDetector
{
    /**
     * Browser families, most specific first: many user agents carry the tokens of the engines they are built on.
     *
     * @var array<string, string>
     */
    private const BROWSERS = [
        'Edge' => '/\b(?:Edg|EdgA|EdgiOS|Edge)\/(\d+)/',
        'Opera' => '/\bOPR\/(\d+)/',
        'Samsung Internet' => '/\bSamsungBrowser\/(\d+)/',
        'Firefox' => '/\b(?:Firefox|FxiOS)\/(\d+)/',
        'Chrome' => '/\b(?:Chrome|CriOS)\/(\d+)/',
        'Safari' => '/\bVersion\/(\d+)[^ ]* (?:Mobile\/\S+ )?Safari\//',
    ];

    /**
     * Operating systems, most specific first: iOS and Android user agents also mention macOS and Linux.
     *
     * @var array<string, string>
     */
    private const SYSTEMS = [
        'iOS' => '/\b(?:iPhone|iPad|iPod)\b/',
        'Android' => '/\bAndroid\b/',
        'ChromeOS' => '/\bCrOS\b/',
        'Windows' => '/\bWindows\b/',
        'macOS' => '/\b(?:Macintosh|Mac OS X)\b/',
        'Linux' => '/\b(?:Linux|X11)\b/',
    ];

    /**
     * Classify the user agent into device type, OS family and browser family with its major version.
     *
     * Known limit: iPadOS 13 and later sends a macOS desktop user agent, so iPads running it count as desktop.
     */
    public function detect(?string $userAgent): DeviceInfo
    {
        if ($userAgent === null || $userAgent === '') {
            return new DeviceInfo(DeviceType::Unknown, null, null, null);
        }

        [$browser, $version] = $this->browser($userAgent);
        $os = $this->system($userAgent);

        return new DeviceInfo($this->deviceType($userAgent, $os, $browser), $os, $browser, $version);
    }

    /**
     * @return array{?string, ?string}
     */
    private function browser(string $userAgent): array
    {
        foreach (self::BROWSERS as $name => $pattern) {
            if (preg_match($pattern, $userAgent, $matches) === 1) {
                return [$name, $matches[1]];
            }
        }

        return [null, null];
    }

    private function system(string $userAgent): ?string
    {
        foreach (self::SYSTEMS as $name => $pattern) {
            if (preg_match($pattern, $userAgent) === 1) {
                return $name;
            }
        }

        return null;
    }

    private function deviceType(string $userAgent, ?string $os, ?string $browser): DeviceType
    {
        if ($os === null && $browser === null) {
            return DeviceType::Unknown;
        }

        if (str_contains($userAgent, 'iPad')) {
            return DeviceType::Tablet;
        }

        if ($os === 'Android') {
            return str_contains($userAgent, 'Mobile') ? DeviceType::Mobile : DeviceType::Tablet;
        }

        return str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPod') || str_contains($userAgent, 'Mobi')
            ? DeviceType::Mobile
            : DeviceType::Desktop;
    }
}
