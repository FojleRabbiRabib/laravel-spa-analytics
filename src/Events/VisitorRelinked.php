<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class VisitorRelinked
{
    use Dispatchable;

    public function __construct(
        public string $previousId,
        public string $visitorId,
    ) {}
}
