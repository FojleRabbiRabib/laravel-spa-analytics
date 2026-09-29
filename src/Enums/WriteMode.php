<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Enums;

enum WriteMode: string
{
    case Defer = 'defer';
    case Queue = 'queue';
    case Sync = 'sync';

    /**
     * Whether this case equals the given case.
     */
    public function is(self $mode): bool
    {
        return $this === $mode;
    }

    /**
     * Human-readable name of the case.
     */
    public function label(): string
    {
        return match ($this) {
            self::Defer => 'Defer',
            self::Queue => 'Queue',
            self::Sync => 'Sync',
        };
    }

    /**
     * Display color token for the case.
     */
    public function color(): string
    {
        return match ($this) {
            self::Defer => 'green',
            self::Queue => 'amber',
            self::Sync => 'gray',
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
            fn (self $mode): array => ['value' => $mode->value, 'label' => $mode->label()],
            self::cases(),
        );
    }

    /**
     * The case used when none is specified.
     */
    public static function default(): self
    {
        return self::Defer;
    }
}
