<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\PageViewData;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class EventStore
{
    public function __construct(
        private readonly VisitorLinkResolver $links,
        private readonly SessionTracker $sessions,
        private readonly int $lockWaitSeconds = 3,
    ) {}

    /**
     * Attach the page view to a session and insert the event.
     *
     * The per-visitor lock is the outermost scope so the commit lands before it is released; the visitor id
     * is resolved again inside the lock so a write racing a re-link never lands under an abandoned id.
     */
    public function store(PageViewData $data): void
    {
        $visitorId = $this->links->resolve($data->visitorId);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $stored = Cache::lock('session:'.$visitorId, 10)->block(
                $this->lockWaitSeconds,
                function () use (&$visitorId, $data): bool {
                    $resolved = $this->links->resolve($visitorId);

                    if ($resolved !== $visitorId) {
                        $visitorId = $resolved;

                        return false;
                    }

                    DB::transaction(function () use ($data, $visitorId): void {
                        $data = $data->withVisitorId($visitorId);
                        $session = $this->sessions->attach($data);

                        AnalyticsEvent::query()->create([...$data->toArray(), 'session_id' => $session->id]);
                    });

                    return true;
                },
            );

            if ($stored) {
                return;
            }
        }

        throw new \RuntimeException('Visitor link changed repeatedly while storing an event.');
    }
}
