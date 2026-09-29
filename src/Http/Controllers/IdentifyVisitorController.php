<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Http\Controllers;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\VisitorIdentity;
use FojleRabbiRabib\LaravelSpaAnalytics\Events\VisitorIdentified;
use FojleRabbiRabib\LaravelSpaAnalytics\Exceptions\InvalidPayload;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Identity\FingerprintHasher;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Identity\PayloadDecoder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdentifyVisitorController
{
    public function __invoke(
        Request $request,
        VisitorIdentity $identity,
        PayloadDecoder $decoder,
        FingerprintHasher $hasher,
    ): JsonResponse {
        try {
            $signals = $decoder->decode($request->getContent(), $identity->id);
        } catch (InvalidPayload) {
            return response()->json(['message' => 'Invalid payload'], 422);
        }

        $header = config('laravel-spa-analytics.identity.tls_fingerprint_header');
        $tls = is_string($header) && $header !== '' ? $request->header($header) : null;

        $identified = $identity->withFingerprint($hasher->hash($signals, is_string($tls) ? $tls : null));

        app()->instance(VisitorIdentity::class, $identified);

        VisitorIdentified::dispatch($identified);

        return response()->json([
            'id' => $identified->id,
            'source' => $identified->source,
        ]);
    }
}
