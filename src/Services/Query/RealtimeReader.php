<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Query;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Query\Realtime;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use Illuminate\Support\Facades\DB;

class RealtimeReader
{
    /**
     * Who is on the site now: the page views of the last window, read from the raw events because rollups only cover
     * completed hours. The latest page of each visitor is picked in PHP, since the window is small.
     */
    public function read(): Realtime
    {
        $minutes = max(1, (int) config('spa-analytics.stats.realtime_minutes'));
        $now = CarbonImmutable::now((string) config('app.timezone'));

        $latest = [];
        $pageViews = 0;

        $events = DB::table('analytics_events')
            ->where('type', EventType::PageView)
            ->where('is_bot', false)
            ->where('occurred_at', '>', $now->subMinutes($minutes)->toDateTimeString())
            ->where('occurred_at', '<=', $now->toDateTimeString())
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->select(['visitor_id', 'path'])
            ->cursor();

        foreach ($events as $event) {
            $pageViews++;
            $latest[$event->visitor_id] = (string) $event->path;
        }

        $counts = array_count_values($latest);
        arsort($counts);

        $pages = [];

        foreach ($counts as $path => $visitors) {
            $pages[] = ['path' => (string) $path, 'visitors' => $visitors];
        }

        return new Realtime(count($latest), $pageViews, $pages, $minutes, $now);
    }
}
