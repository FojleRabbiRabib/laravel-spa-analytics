<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Data\Query;

use Carbon\CarbonImmutable;

final readonly class Realtime
{
    /**
     * @param  int  $activeVisitors  Distinct visitors with a page view in the window.
     * @param  array<int, array{path: string, visitors: int}>  $pages  The page each active visitor viewed last, most visitors first.
     */
    public function __construct(
        public int $activeVisitors,
        public int $pageViews,
        public array $pages,
        public int $windowMinutes,
        public CarbonImmutable $asOf,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'activeVisitors' => $this->activeVisitors,
            'pageViews' => $this->pageViews,
            'pages' => $this->pages,
            'windowMinutes' => $this->windowMinutes,
            'asOf' => $this->asOf->toIso8601String(),
        ];
    }
}
