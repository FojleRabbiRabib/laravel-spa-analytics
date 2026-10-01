<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Contracts;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\DeviceInfo;

interface DeviceDetector
{
    /**
     * Classify the user agent; an unknown or missing one gives DeviceType::Unknown with no OS or browser.
     */
    public function detect(?string $userAgent): DeviceInfo;
}
