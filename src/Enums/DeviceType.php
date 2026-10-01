<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Enums;

enum DeviceType: string
{
    case Desktop = 'desktop';
    case Mobile = 'mobile';
    case Tablet = 'tablet';
    case Unknown = 'unknown';

    /**
     * Whether this case equals the given case.
     */
    public function is(self $type): bool
    {
        return $this === $type;
    }

    /**
     * Human-readable name of the case.
     */
    public function label(): string
    {
        return match ($this) {
            self::Desktop => 'Desktop',
            self::Mobile => 'Mobile',
            self::Tablet => 'Tablet',
            self::Unknown => 'Unknown',
        };
    }

    /**
     * Display color token for the case.
     */
    public function color(): string
    {
        return match ($this) {
            self::Desktop => 'blue',
            self::Mobile => 'green',
            self::Tablet => 'purple',
            self::Unknown => 'gray',
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
     * The case for a case or its backing string.
     */
    public static function coerce(self|string $type): self
    {
        return $type instanceof self ? $type : self::from($type);
    }

    /**
     * The case used when none is specified.
     */
    public static function default(): self
    {
        return self::Unknown;
    }
}
