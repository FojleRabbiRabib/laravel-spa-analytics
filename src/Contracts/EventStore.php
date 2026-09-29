<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Contracts;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\PageViewData;

interface EventStore
{
    /**
     * Persist the page view together with whatever it belongs to (its session).
     *
     * May throw: the writer catches, logs the exception class and code, and never lets it reach the visitor.
     */
    public function store(PageViewData $data): void;
}
