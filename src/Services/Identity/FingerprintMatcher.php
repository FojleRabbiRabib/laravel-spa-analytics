<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Identity;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\Fingerprint;

class FingerprintMatcher
{
    /**
     * Two fingerprints match when the stable hashes are equal and at least one volatile tier is equal.
     */
    public function matches(Fingerprint $a, Fingerprint $b): bool
    {
        if (! hash_equals($a->stableHash, $b->stableHash)) {
            return false;
        }

        return $this->tierMatches($a->canvasHash, $b->canvasHash)
            || $this->tierMatches($a->audioHash, $b->audioHash)
            || $this->tierMatches($a->webglHash, $b->webglHash)
            || $this->tierMatches($a->tlsHash, $b->tlsHash);
    }

    private function tierMatches(?string $first, ?string $second): bool
    {
        return $first !== null && $second !== null && hash_equals($first, $second);
    }
}
