<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\PageViewData;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\WriteMode;
use FojleRabbiRabib\LaravelSpaAnalytics\Jobs\WriteEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\Event as AnalyticsEvent;
use Illuminate\Support\Facades\Log;

class EventWriter
{
    /**
     * Store the page view using the configured write mode.
     */
    public function write(PageViewData $data): void
    {
        $attributes = $data->toArray();

        $mode = WriteMode::tryFrom((string) config('laravel-spa-analytics.tracking.write_mode')) ?? WriteMode::default();

        match ($mode) {
            WriteMode::Sync => $this->safely($attributes),
            WriteMode::Queue => dispatch(
                (new WriteEvent($attributes))
                    ->onConnection(config('laravel-spa-analytics.tracking.connection'))
                    ->onQueue(config('laravel-spa-analytics.tracking.queue')),
            ),
            WriteMode::Defer => defer(fn () => $this->safely($attributes), always: true),
        };
    }

    /**
     * Insert the row, logging only the exception class and code so bound values never reach the log.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function safely(array $attributes): void
    {
        try {
            AnalyticsEvent::query()->create($attributes);
        } catch (\Throwable $e) {
            Log::warning('spa-analytics: event write failed', ['exception' => $e::class, 'code' => $e->getCode()]);
        }
    }
}
