<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Events;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\VisitorIdentity;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class VisitorIdentified
{
    use Dispatchable;

    public function __construct(public VisitorIdentity $identity) {}
}
