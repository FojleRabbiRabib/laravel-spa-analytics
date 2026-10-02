<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Http\Controllers;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\VisitorIdentity;
use FojleRabbiRabib\LaravelSpaAnalytics\Http\Requests\CollectEventsRequest;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\ClientEventRecorder;
use Illuminate\Http\Response;

class CollectEventsController
{
    /**
     * Record the events the browser reports for the current visitor; nothing is returned.
     */
    public function __invoke(CollectEventsRequest $request, VisitorIdentity $identity, ClientEventRecorder $recorder): Response
    {
        /** @var array<int, array<string, mixed>> $events */
        $events = $request->validated('events');

        $recorder->record($request, $identity->id, $events);

        return response()->noContent();
    }
}
