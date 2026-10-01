<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\DeviceType;

final readonly class AudienceData
{
    public function __construct(
        public ?DeviceType $deviceType = null,
        public ?string $os = null,
        public ?string $browser = null,
        public ?string $browserVersion = null,
        public ?string $country = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Column-keyed values; missing keys mean unknown.
     */
    public static function fromArray(array $data): self
    {
        $deviceType = $data['device_type'] ?? null;

        return new self(
            deviceType: $deviceType === null ? null : DeviceType::coerce($deviceType),
            os: isset($data['os']) ? (string) $data['os'] : null,
            browser: isset($data['browser']) ? (string) $data['browser'] : null,
            browserVersion: isset($data['browser_version']) ? (string) $data['browser_version'] : null,
            country: isset($data['country']) ? (string) $data['country'] : null,
        );
    }

    /**
     * Column-keyed attributes for the sessions table.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'device_type' => $this->deviceType,
            'os' => $this->os,
            'browser' => $this->browser,
            'browser_version' => $this->browserVersion,
            'country' => $this->country,
        ];
    }
}
