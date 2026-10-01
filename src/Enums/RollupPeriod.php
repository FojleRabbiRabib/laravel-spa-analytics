<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

enum RollupPeriod: string
{
    case Hour = 'hour';
    case Day = 'day';

    /**
     * Whether this case equals the given case.
     */
    public function is(self $period): bool
    {
        return $this === $period;
    }

    /**
     * Human-readable name of the case.
     */
    public function label(): string
    {
        return match ($this) {
            self::Hour => 'Hour',
            self::Day => 'Day',
        };
    }

    /**
     * Display color token for the case.
     */
    public function color(): string
    {
        return match ($this) {
            self::Hour => 'blue',
            self::Day => 'green',
        };
    }

    /**
     * The start of the bucket that contains the moment.
     */
    public function start(CarbonInterface $at): CarbonImmutable
    {
        $at = CarbonImmutable::instance($at);

        return match ($this) {
            self::Hour => $at->startOfHour(),
            self::Day => $at->startOfDay(),
        };
    }

    /**
     * The exclusive end of the bucket, which is the start of the next one.
     */
    public function end(CarbonInterface $start): CarbonImmutable
    {
        $start = CarbonImmutable::instance($start);

        return match ($this) {
            self::Hour => $start->addHour(),
            self::Day => $start->addDay(),
        };
    }

    /**
     * Backing values of every case.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Value and label pairs for every case.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $period): array => ['value' => $period->value, 'label' => $period->label()],
            self::cases(),
        );
    }

    /**
     * The case used when none is specified.
     */
    public static function default(): self
    {
        return self::Hour;
    }
}
