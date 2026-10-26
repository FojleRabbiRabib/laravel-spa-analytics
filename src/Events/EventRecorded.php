<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Events;

use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched after a page view, custom event, goal, outbound click, file download or scroll depth row is committed
 * by the package; in sync mode inside the app's own transaction that is before the app's commit. A custom
 * EventStore binding has to dispatch it itself.
 */
final readonly class EventRecorded
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public AnalyticsEvent $event,
    ) {}
}
