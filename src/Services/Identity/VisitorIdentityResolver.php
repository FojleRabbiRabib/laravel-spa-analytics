<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Identity;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\VisitorIdentity;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\IdentitySource;
use FojleRabbiRabib\LaravelSpaAnalytics\Support\VisitorId;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class VisitorIdentityResolver
{
    /**
     * Read the visitor cookie, or mint a new id when it is missing or invalid.
     */
    public function resolve(Request $request): VisitorIdentity
    {
        $cookie = VisitorId::normalize($request->cookie((string) config('spa-analytics.identity.cookie_name')));

        if ($cookie !== null) {
            return new VisitorIdentity($cookie, IdentitySource::Cookie);
        }

        return new VisitorIdentity((string) Str::uuid(), IdentitySource::Generated);
    }
}
