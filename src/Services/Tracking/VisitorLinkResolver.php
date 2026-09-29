<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Models\VisitorLink;

class VisitorLinkResolver
{
    /**
     * Return the adopted visitor id when the given id was re-linked, otherwise the id itself.
     */
    public function resolve(string $visitorId): string
    {
        $linkedTo = VisitorLink::query()->where('visitor_id', $visitorId)->value('linked_to');

        return is_string($linkedTo) ? $linkedTo : $visitorId;
    }
}
