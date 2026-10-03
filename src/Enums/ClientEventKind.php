<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Enums;

enum ClientEventKind: string
{
    case PageView = 'pageview';
    case Outbound = 'outbound';
    case Scroll = 'scroll';
    case Event = 'event';
    case Goal = 'goal';
    case Download = 'download';

    /**
     * Whether this case equals the given case.
     */
    public function is(self $kind): bool
    {
        return $this === $kind;
    }

    /**
     * Human-readable name of the case.
     */
    public function label(): string
    {
        return match ($this) {
            self::PageView => 'Page view',
            self::Outbound => 'Outbound click',
            self::Scroll => 'Scroll depth',
            self::Event => 'Custom event',
            self::Goal => 'Goal',
            self::Download => 'File download',
        };
    }

    /**
     * Display color token for the case.
     */
    public function color(): string
    {
        return match ($this) {
            self::PageView => 'blue',
            self::Outbound => 'orange',
            self::Scroll => 'gray',
            self::Event => 'purple',
            self::Goal => 'green',
            self::Download => 'orange',
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
            fn (self $kind): array => ['value' => $kind->value, 'label' => $kind->label()],
            self::cases(),
        );
    }

    /**
     * The case used when none is specified.
     */
    public static function default(): self
    {
        return self::PageView;
    }
}
