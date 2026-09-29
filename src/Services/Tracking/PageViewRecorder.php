<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\BotDetector;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\VisitorIdentity;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\PageViewData;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PageViewRecorder
{
    public function __construct(
        private readonly PathNormalizer $paths,
        private readonly UtmParser $utm,
        private readonly BotDetector $bots,
        private readonly ReferrerClassifier $referrers,
        private readonly EventWriter $writer,
    ) {}

    /**
     * Record the request as a page view when it qualifies; anything else is silently skipped.
     */
    public function record(Request $request, Response $response): void
    {
        if (! $this->shouldRecord($request, $response)) {
            return;
        }

        $identity = app(VisitorIdentity::class);
        $referrer = $this->referrers->classify($request->headers->get('referer'), $request->getHost());
        $userAgent = $request->userAgent();

        $this->writer->write(new PageViewData(
            type: EventType::PageView,
            visitorId: $identity->id,
            path: $this->paths->normalize($request->path()),
            status: $response->getStatusCode(),
            referrerHost: $referrer->host,
            referrerType: $referrer->type,
            utm: $this->utm->parse($request->query()),
            language: $this->language($request),
            ip: $request->ip(),
            userAgent: $userAgent === null ? null : mb_substr($userAgent, 0, 512),
            isBot: $this->bots->isBot($userAgent),
            occurredAt: now()->toImmutable(),
        ));
    }

    private function shouldRecord(Request $request, Response $response): bool
    {
        if (! config('spa-analytics.enabled') || ! app()->bound(VisitorIdentity::class)) {
            return false;
        }

        $status = $response->getStatusCode();

        if (! $request->isMethod('GET') || ($status >= 300 && $status < 400) || $status === 409) {
            return false;
        }

        if ($request->is(...(array) config('spa-analytics.tracking.excluded_paths'))) {
            return false;
        }

        if ($this->isPrefetch($request)) {
            return false;
        }

        if ($request->headers->has('X-Inertia')) {
            return ! $request->headers->has('X-Inertia-Partial-Component')
                && ! $request->headers->has('X-Inertia-Partial-Data');
        }

        return ! $request->ajax()
            && str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')
            && $this->isDocumentRequest($request);
    }

    /**
     * Whether the client asked for an HTML document rather than an image, script or other resource.
     */
    private function isDocumentRequest(Request $request): bool
    {
        if ($request->headers->has('Sec-Fetch-Dest') && $request->headers->get('Sec-Fetch-Dest') !== 'document') {
            return false;
        }

        return str_contains((string) $request->headers->get('Accept'), 'text/html');
    }

    private function isPrefetch(Request $request): bool
    {
        return strtolower((string) $request->headers->get('Purpose')) === 'prefetch'
            || str_contains(strtolower((string) $request->headers->get('Sec-Purpose')), 'prefetch');
    }

    private function language(Request $request): ?string
    {
        $header = (string) $request->headers->get('Accept-Language');
        $tag = trim(explode(';', explode(',', $header)[0])[0]);

        return $tag === '' ? null : mb_substr($tag, 0, 16);
    }
}
