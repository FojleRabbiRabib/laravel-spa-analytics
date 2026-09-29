<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Jobs;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\PageViewData;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\EventStore;
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
     * Attach the page view to a session and insert the event row.
     */
    public function handle(EventStore $store): void
    {
        $store->store(PageViewData::fromArray($this->attributes));
    }
}
