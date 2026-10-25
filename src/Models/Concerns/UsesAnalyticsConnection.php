<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Models\Concerns;

use FojleRabbiRabib\LaravelSpaAnalytics\Support\AnalyticsDatabase;

trait UsesAnalyticsConnection
{
    /**
     * The configured analytics connection; a subclass that sets its own $connection keeps it.
     */
    public function getConnectionName(): ?string
    {
        return parent::getConnectionName() ?? AnalyticsDatabase::name();
    }
}
