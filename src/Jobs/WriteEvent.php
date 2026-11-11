<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Jobs;

use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\EventStore;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\CustomEventData;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\PageViewData;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The payload holds the visitor's IP address and user agent, so the job is encrypted. Exception messages kept in
 * failed_jobs are not covered. Queues deliver at least once; the event id in the payload makes a second run harmless.
 * Run the worker with a --timeout a few seconds below the connection's retry_after.
 */
class WriteEvent implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 5;

    public int $timeout = 30;

    /**
     * @param  array<string, mixed>  $attributes  Column-keyed values for the events table.
     */
    public function __construct(private readonly array $attributes) {}

    /**
     * Rebuild the page view or custom event and hand it to the event store.
     */
    public function handle(EventStore $store): void
    {
        $store->store(
            EventType::coerce($this->attributes['type'])->is(EventType::PageView)
                ? PageViewData::fromArray($this->attributes)
                : CustomEventData::fromArray($this->attributes),
        );
    }

    /**
     * Seconds to wait before each retry, so a busy session lock or a brief database outage can clear.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 30, 120, 300];
    }

    /**
     * Log the final failure with the exception class and code only, so bound values never reach the log.
     */
    public function failed(\Throwable $e): void
    {
        Log::warning('spa-analytics: queued event write failed', ['exception' => $e::class, 'code' => $e->getCode()]);
    }
}
