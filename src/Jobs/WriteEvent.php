<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Jobs;

use FojleRabbiRabib\LaravelSpaAnalytics\Models\Event;
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
     * Insert the event row.
     */
    public function handle(): void
    {
        Event::query()->create($this->attributes);
    }
}
