<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Identity;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\Fingerprint;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\FingerprintSignals;

class FingerprintHasher
{
    /**
     * Hash device signals into one stable tier plus separate volatile tiers.
     */
    public function hash(FingerprintSignals $signals, ?string $tlsFingerprint = null): Fingerprint
    {
        $stable = hash('sha256', implode('|', [
            min($signals->screenWidth, $signals->screenHeight),
            max($signals->screenWidth, $signals->screenHeight),
            $signals->colorDepth,
            $signals->timezone,
            $signals->hardwareConcurrency,
            $signals->deviceMemory,
            $signals->platform,
            $signals->languages,
            $signals->maxTouchPoints,
        ]));

        $webgl = $signals->webglVendor === '' && $signals->webglRenderer === ''
            ? null
            : $signals->webglVendor.'|'.$signals->webglRenderer;

        return new Fingerprint(
            stableHash: $stable,
            canvasHash: $this->tier('canvas', $signals->canvasHash),
            audioHash: $this->tier('audio', $signals->audioHash),
            webglHash: $this->tier('webgl', $webgl),
            tlsHash: $this->tier('tls', $tlsFingerprint),
        );
    }

    private function tier(string $label, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return hash('sha256', $label.'|'.$value);
    }
}
