<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Identity;

use Symfony\Component\HttpFoundation\Cookie;

class VisitorCookieFactory
{
    /**
     * Build the visitor cookie: HttpOnly, SameSite=Lax, lifetime from config.
     */
    public function make(string $visitorId, bool $secure): Cookie
    {
        return cookie(
            (string) config('spa-analytics.identity.cookie_name'),
            $visitorId,
            (int) config('spa-analytics.identity.cookie_lifetime_days') * 1440,
            '/',
            null,
            $secure,
            true,
            false,
            'lax',
        );
    }
}
