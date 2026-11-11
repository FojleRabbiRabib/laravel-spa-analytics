<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\EventStore;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\CustomEventData;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\PageViewData;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\WriteMode;
use FojleRabbiRabib\LaravelSpaAnalytics\Jobs\WriteEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EventWriter
{
    public function __construct(private readonly EventStore $store) {}

    /**
     * Store the page view or custom event using the configured write mode.
     *
     * The event gets its id here, once, so a queued job that runs again after a failure or a lost acknowledgement
     * carries the same id and the store can recognise it.
     */
    public function write(PageViewData|CustomEventData $data): void
    {
        $mode = WriteMode::tryFrom((string) config('spa-analytics.tracking.write_mode')) ?? WriteMode::default();
        $data = $data->eventId === null ? $data->withEventId((string) Str::ulid()) : $data;

        match ($mode) {
            WriteMode::Sync => $this->safely($data),
            WriteMode::Queue => dispatch(
                (new WriteEvent($data instanceof PageViewData ? $data->toPayload() : $data->toArray()))
                    ->onConnection(config('spa-analytics.tracking.connection'))
                    ->onQueue(config('spa-analytics.tracking.queue')),
            ),
            WriteMode::Defer => defer(fn () => $this->safely($data), always: true),
        };
    }

    /**
     * Store the event, logging only the exception class and code so bound values never reach the log.
     */
    private function safely(PageViewData|CustomEventData $data): void
    {
        try {
            $this->store->store($data);
        } catch (\Throwable $e) {
            Log::warning('spa-analytics: event write failed', ['exception' => $e::class, 'code' => $e->getCode()]);
        }
    }
}
