<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\EventStore;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\CustomEventData;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\PageViewData;
use FojleRabbiRabib\LaravelSpaAnalytics\Events\EventRecorded;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Support\AnalyticsDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DatabaseEventStore implements EventStore
{
    public function __construct(
        private readonly VisitorLinkResolver $links,
        private readonly SessionTracker $sessions,
        private readonly int $lockWaitSeconds = 3,
    ) {}

    /**
     * Insert the event; a page view joins or opens a session, a custom event only attaches to one that is still active.
     *
     * The per-visitor lock is the outermost scope so the commit lands before it is released; the visitor id
     * is resolved again inside the lock so a write racing a re-link never lands under an abandoned id.
     */
    public function store(PageViewData|CustomEventData $data): void
    {
        $visitorId = $this->links->resolve($data->visitorId);
        $event = null;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $stored = Cache::lock('session:'.$visitorId, 10)->block(
                $this->lockWaitSeconds,
                function () use (&$visitorId, &$event, $data): bool {
                    $resolved = $this->links->resolve($visitorId);

                    if ($resolved !== $visitorId) {
                        $visitorId = $resolved;

                        return false;
                    }

                    $event = AnalyticsDatabase::connection()->transaction(function () use ($data, $visitorId): AnalyticsEvent {
                        $data = $data->withVisitorId($visitorId);
                        $sessionId = $data instanceof CustomEventData
                            ? $this->sessions->findActive($data->visitorId, $data->occurredAt)?->id
                            : $this->sessions->attach($data)->id;

                        return AnalyticsEvent::query()->create([...$data->toArray(), 'session_id' => $sessionId]);
                    });

                    return true;
                },
            );

            if ($stored) {
                $this->announce($event);

                return;
            }
        }

        throw new \RuntimeException('Visitor link changed repeatedly while storing an event.');
    }

    /**
     * Tell the listeners after the commit and the lock are gone; a failing listener must never undo or repeat the write.
     */
    private function announce(AnalyticsEvent $event): void
    {
        try {
            EventRecorded::dispatch($event);
        } catch (\Throwable $e) {
            Log::warning('spa-analytics: event listener failed', ['exception' => $e::class, 'code' => $e->getCode()]);
        }
    }
}
