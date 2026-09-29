<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Listeners;

use FojleRabbiRabib\LaravelSpaAnalytics\Events\VisitorIdentified;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\VisitorFingerprint;

class StoreVisitorFingerprint
{
    /**
     * Upsert the visitor's fingerprint hashes and refresh last_seen_at; first_seen_at is kept.
     */
    public function handle(VisitorIdentified $event): void
    {
        $fingerprint = $event->identity->fingerprint;

        if ($fingerprint === null) {
            return;
        }

        $now = now();

        VisitorFingerprint::query()->upsert(
            [[
                'visitor_id' => $event->identity->id,
                'stable_hash' => $fingerprint->stableHash,
                'canvas_hash' => $fingerprint->canvasHash,
                'audio_hash' => $fingerprint->audioHash,
                'webgl_hash' => $fingerprint->webglHash,
                'tls_hash' => $fingerprint->tlsHash,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ]],
            ['visitor_id'],
            ['stable_hash', 'canvas_hash', 'audio_hash', 'webgl_hash', 'tls_hash', 'last_seen_at'],
        );
    }
}
