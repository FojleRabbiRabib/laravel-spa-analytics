<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Support;

use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\EventStore;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\PageViewData;

final class FakeEventStore implements EventStore
{
    /** @var array<int, PageViewData> */
    public static array $stored = [];

    public function store(PageViewData $data): void
    {
        self::$stored[] = $data;
    }
}
