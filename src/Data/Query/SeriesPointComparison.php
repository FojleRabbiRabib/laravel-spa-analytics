<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Data\Query;

final readonly class SeriesPointComparison
{
    /**
     * @param  ?SeriesPoint  $previous  The point at the same place of the previous period; null when that period has fewer points.
     * @param  array<string, Change>  $changes
     */
    public function __construct(
        public SeriesPoint $current,
        public ?SeriesPoint $previous,
        public array $changes,
    ) {}

    public static function between(SeriesPoint $current, ?SeriesPoint $previous): self
    {
        $numbers = fn (?SeriesPoint $point): array => [
            'pageViews' => $point->pageViews ?? 0,
            'visitors' => $point->visitors ?? 0,
            'sessions' => $point->sessions ?? 0,
            'bounces' => $point->bounces ?? 0,
            'events' => $point->events ?? 0,
        ];

        return new self($current, $previous, [
            ...Change::all($numbers($current), $numbers($previous)),
            'revenue' => Change::money($current->revenue, $previous->revenue ?? '0.00'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'current' => $this->current->toArray(),
            'previous' => $this->previous?->toArray(),
            'changes' => array_map(fn (Change $change): array => $change->toArray(), $this->changes),
        ];
    }
}
