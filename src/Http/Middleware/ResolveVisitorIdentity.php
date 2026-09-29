<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Http\Middleware;

use Closure;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\VisitorIdentity;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Identity\VisitorCookieFactory;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Identity\VisitorIdentityResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

class ResolveVisitorIdentity
{
    public function __construct(
        private readonly VisitorIdentityResolver $resolver,
        private readonly VisitorCookieFactory $cookies,
    ) {}

    /**
     * Resolve the visitor identity, bind it into the container and queue the visitor cookie.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('spa-analytics.enabled')) {
            return $next($request);
        }

        $identity = $this->resolver->resolve($request);

        app()->instance(VisitorIdentity::class, $identity);

        Cookie::queue($this->cookies->make($identity->id, $request->isSecure()));

        return $next($request);
    }
}
