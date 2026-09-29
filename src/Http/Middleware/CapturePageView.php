<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Http\Middleware;

use Closure;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\PageViewRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CapturePageView
{
    public function __construct(private readonly PageViewRecorder $recorder) {}

    /**
     * Record the page view after the response exists; analytics failures never reach the visitor.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $this->recorder->record($request, $response);
        } catch (\Throwable $e) {
            Log::warning('spa-analytics: page view capture failed', ['exception' => $e::class, 'code' => $e->getCode()]);
        }

        return $response;
    }
}
