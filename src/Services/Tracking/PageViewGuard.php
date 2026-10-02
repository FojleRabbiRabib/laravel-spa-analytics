<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking;

use Illuminate\Support\Facades\Cache;

class PageViewGuard
{
    private const SECONDS = 5;

    /**
     * Mark the visitor's page view of this path as seen; false when it was already seen within the last few seconds.
     *
     * The server records a visit and the browser may report the same one as a client transition, so both claim
     * the visitor and path here when they record, not when the deferred write runs.
     */
    public function claim(string $visitorId, string $path): bool
    {
        return Cache::add('page-view:'.sha1($visitorId."\0".$path), 1, self::SECONDS);
    }
}
