<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Identity;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\VisitorIdentity;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\IdentitySource;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class VisitorIdentityResolver
{
    public function resolve(Request $request): VisitorIdentity
    {
        $cookie = $request->cookie((string) config('laravel-spa-analytics.identity.cookie_name'));

        if (is_string($cookie) && Str::isUuid($cookie)) {
            return new VisitorIdentity($cookie, IdentitySource::Cookie);
        }

        return new VisitorIdentity((string) Str::uuid(), IdentitySource::Generated);
    }
}
