<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Enums;

enum EventType: string
{
    case PageView = 'page_view';

    /**
     * Whether this case equals any of the given cases.
     */
    public function is(self ...$types): bool
    {
        return in_array($this, $types, true);
    }

    /**
     * Human-readable name of the case.
     */
    public function label(): string
    {
        return match ($this) {
            self::PageView => 'Page view',
        };
    }

    /**
     * Display color token for the case.
     */
    public function color(): string
    {
        return match ($this) {
            self::PageView => 'blue',
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
            fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
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
