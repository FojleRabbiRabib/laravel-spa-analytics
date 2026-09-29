<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\PageViewData;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\WriteMode;
use FojleRabbiRabib\LaravelSpaAnalytics\Jobs\WriteEvent;
use Illuminate\Support\Facades\Log;

class EventWriter
{
    public function __construct(private readonly EventStore $store) {}

    /**
     * Store the page view using the configured write mode.
     */
    public function write(PageViewData $data): void
    {
        $mode = WriteMode::tryFrom((string) config('spa-analytics.tracking.write_mode')) ?? WriteMode::default();

        match ($mode) {
            WriteMode::Sync => $this->safely($data),
            WriteMode::Queue => dispatch(
                (new WriteEvent($data->toArray()))
                    ->onConnection(config('spa-analytics.tracking.connection'))
                    ->onQueue(config('spa-analytics.tracking.queue')),
            ),
            WriteMode::Defer => defer(fn () => $this->safely($data), always: true),
        };
    }

    /**
     * Store the page view, logging only the exception class and code so bound values never reach the log.
     */
    private function safely(PageViewData $data): void
    {
        try {
            $this->store->store($data);
        } catch (\Throwable $e) {
            Log::warning('spa-analytics: event write failed', ['exception' => $e::class, 'code' => $e->getCode()]);
        }
    }
}
