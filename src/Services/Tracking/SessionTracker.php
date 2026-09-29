<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\PageViewData;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;

class SessionTracker
{
    /**
     * Continue the visitor's session or open a new one for this page view.
     *
     * The caller must hold the "session:{visitor_id}" cache lock and a transaction around this call.
     */
    public function attach(PageViewData $data): AnalyticsSession
    {
        $timeout = (int) config('spa-analytics.sessions.timeout_minutes');

        $latest = AnalyticsSession::query()
            ->where('visitor_id', $data->visitorId)
            ->orderByDesc('last_seen_at')
            ->first();

        $inWindow = $latest !== null
            && $data->occurredAt->lessThanOrEqualTo($latest->last_seen_at->copy()->addMinutes($timeout))
            && $data->occurredAt->greaterThanOrEqualTo($latest->started_at->copy()->subMinutes($timeout));

        if ($latest !== null && $inWindow) {
            $latest->page_views++;

            if ($data->occurredAt->greaterThan($latest->last_seen_at)) {
                $latest->last_seen_at = $data->occurredAt;
                $latest->exit_path = $data->path;
            }

            if ($data->occurredAt->lessThan($latest->started_at)) {
                $latest->started_at = $data->occurredAt;
                $latest->entry_path = $data->path;
            }

            $latest->save();

            return $latest;
        }

        return AnalyticsSession::query()->create([
            'visitor_id' => $data->visitorId,
            'started_at' => $data->occurredAt,
            'last_seen_at' => $data->occurredAt,
            'entry_path' => $data->path,
            'exit_path' => $data->path,
            'page_views' => 1,
            'referrer_host' => $data->referrerHost,
            'referrer_type' => $data->referrerType,
            ...$data->utm,
            'is_new_visitor' => $latest === null,
            'is_bot' => $data->isBot,
        ]);
    }
}
