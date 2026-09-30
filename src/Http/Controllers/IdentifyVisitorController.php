<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Http\Controllers;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\VisitorIdentity;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\IdentitySource;
use FojleRabbiRabib\LaravelSpaAnalytics\Events\VisitorIdentified;
use FojleRabbiRabib\LaravelSpaAnalytics\Exceptions\InvalidPayload;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Identity\FingerprintHasher;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Identity\PayloadDecoder;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Identity\VisitorCookieFactory;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Identity\VisitorRelinker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class IdentifyVisitorController
{
    /**
     * Decode the encrypted signals, attach the fingerprint, re-link a returning visitor and announce the identity.
     */
    public function __invoke(
        Request $request,
        VisitorIdentity $identity,
        PayloadDecoder $decoder,
        FingerprintHasher $hasher,
        VisitorRelinker $relinker,
        VisitorCookieFactory $cookies,
    ): JsonResponse {
        try {
            $signals = $decoder->decode($request->getContent(), $identity->id);
        } catch (InvalidPayload) {
            return response()->json(['message' => 'Invalid payload'], 422);
        }

        $header = config('spa-analytics.identity.tls_fingerprint_header');
        $tls = is_string($header) && $header !== '' ? $request->header($header) : null;

        $identified = $identity->withFingerprint($hasher->hash($signals, is_string($tls) ? $tls : null));

        $adopted = $relinker->relink($identified);

        if ($adopted !== null) {
            $identified = new VisitorIdentity($adopted, IdentitySource::Relinked, $identified->fingerprint);

            Cookie::queue($cookies->make($adopted, $request->isSecure()));
        }

        app()->instance(VisitorIdentity::class, $identified);

        VisitorIdentified::dispatch($identified);

        return response()->json([
            'id' => $identified->id,
            'source' => $identified->source,
        ]);
    }
}
