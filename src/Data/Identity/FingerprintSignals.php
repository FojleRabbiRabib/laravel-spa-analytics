<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity;

final readonly class FingerprintSignals
{
    /**
     * @param  int  $deviceMemory  hundredths of a gigabyte (8 GB = 800)
     */
    public function __construct(
        public int $screenWidth,
        public int $screenHeight,
        public int $colorDepth,
        public string $timezone,
        public int $hardwareConcurrency,
        public int $deviceMemory,
        public string $platform,
        public string $languages,
        public int $maxTouchPoints,
        public string $webglVendor,
        public string $webglRenderer,
        public ?string $canvasHash,
        public ?string $audioHash,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $canvas = (string) ($data['canvasHash'] ?? '');
        $audio = (string) ($data['audioHash'] ?? '');

        return new self(
            screenWidth: (int) $data['screenWidth'],
            screenHeight: (int) $data['screenHeight'],
            colorDepth: (int) $data['colorDepth'],
            timezone: (string) $data['timezone'],
            hardwareConcurrency: (int) $data['hardwareConcurrency'],
            deviceMemory: (int) $data['deviceMemory'],
            platform: (string) $data['platform'],
            languages: (string) $data['languages'],
            maxTouchPoints: (int) $data['maxTouchPoints'],
            webglVendor: (string) $data['webglVendor'],
            webglRenderer: (string) $data['webglRenderer'],
            canvasHash: $canvas !== '' ? $canvas : null,
            audioHash: $audio !== '' ? $audio : null,
        );
    }
}
