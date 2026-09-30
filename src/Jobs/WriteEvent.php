<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Jobs;

use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\EventStore;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\CustomEventData;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\PageViewData;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class WriteEvent implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

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
}
