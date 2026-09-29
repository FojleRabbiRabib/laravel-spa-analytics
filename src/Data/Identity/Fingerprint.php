<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity;

final readonly class Fingerprint
{
    public function __construct(
        public string $stableHash,
        public ?string $canvasHash,
        public ?string $audioHash,
        public ?string $webglHash,
        public ?string $tlsHash,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            stableHash: (string) $data['stableHash'],
            canvasHash: isset($data['canvasHash']) ? (string) $data['canvasHash'] : null,
            audioHash: isset($data['audioHash']) ? (string) $data['audioHash'] : null,
            webglHash: isset($data['webglHash']) ? (string) $data['webglHash'] : null,
            tlsHash: isset($data['tlsHash']) ? (string) $data['tlsHash'] : null,
        );
    }
}
