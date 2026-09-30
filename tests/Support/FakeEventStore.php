<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Support;

use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\EventStore;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\CustomEventData;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\PageViewData;

final class FakeEventStore implements EventStore
{
    /** @var array<int, PageViewData|CustomEventData> */
    public static array $stored = [];

    public function store(PageViewData|CustomEventData $data): void
    {
        self::$stored[] = $data;
    }
}
