<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsSession;

class SessionMerger
{
    /**
     * Fold sessions that now belong to one visitor into a single session when they sit within the session timeout.
     *
     * Only chains that contain one of the moved sessions are merged. The earliest session survives and keeps
     * its entry path and attribution; events are pointed at it before the absorbed rows are deleted.
     * The caller must hold the "session:{visitor_id}" cache lock and a transaction around this call.
     *
     * @param  list<int>  $movedSessionIds
     */
    public function merge(string $visitorId, array $movedSessionIds): void
    {
        if ($movedSessionIds === []) {
            return;
        }

        $timeout = (int) config('spa-analytics.sessions.timeout_minutes');

        $moved = AnalyticsSession::query()->whereKey($movedSessionIds)->get();
        $from = $moved->min('started_at')->copy()->subMinutes($timeout);
        $to = $moved->max('last_seen_at')->copy()->addMinutes($timeout);

        $candidates = AnalyticsSession::query()
            ->where('visitor_id', $visitorId)
            ->where('last_seen_at', '>=', $from)
            ->where('started_at', '<=', $to)
            ->orderBy('started_at')
            ->orderBy('id')
            ->get();

        $chain = [];
        $reach = null;

        foreach ($candidates as $session) {
            if ($chain !== [] && $session->started_at->greaterThan($reach)) {
                $this->fold($chain, $movedSessionIds);
                $chain = [];
                $reach = null;
            }

            $chain[] = $session;
            $end = $session->last_seen_at->copy()->addMinutes($timeout);
            $reach = $reach === null || $end->greaterThan($reach) ? $end : $reach;
        }

        $this->fold($chain, $movedSessionIds);
    }

    /**
     * @param  list<AnalyticsSession>  $chain
     * @param  list<int>  $movedSessionIds
     */
    private function fold(array $chain, array $movedSessionIds): void
    {
        if (count($chain) < 2 || array_intersect(array_map(fn (AnalyticsSession $s): int => $s->id, $chain), $movedSessionIds) === []) {
            return;
        }

        $survivor = array_shift($chain);

        foreach ($chain as $absorbed) {
            $survivor->page_views += $absorbed->page_views;
            $survivor->is_new_visitor = $survivor->is_new_visitor || $absorbed->is_new_visitor;

            if ($absorbed->last_seen_at->greaterThan($survivor->last_seen_at)) {
                $survivor->last_seen_at = $absorbed->last_seen_at;
                $survivor->exit_path = $absorbed->exit_path;
            }

            AnalyticsEvent::query()->where('session_id', $absorbed->id)->update(['session_id' => $survivor->id]);
            $absorbed->delete();
        }

        $survivor->save();
    }
}
