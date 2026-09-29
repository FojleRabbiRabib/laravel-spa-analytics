<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Http\Controllers;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\VisitorIdentity;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Identity\HandshakeTokenIssuer;
use Illuminate\Http\JsonResponse;

class IdentityHandshakeController
{
    /**
     * Return a fresh nonce, per-session key and expiry for the current visitor.
     */
    public function __invoke(VisitorIdentity $identity, HandshakeTokenIssuer $issuer): JsonResponse
    {
        $handshake = $issuer->issue($identity->id);

        return response()->json([
            'nonce' => $handshake->nonce,
            'key' => $handshake->key,
            'expires_at' => $handshake->expiresAt,
        ]);
    }
}
