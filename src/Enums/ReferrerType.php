<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Enums;

enum ReferrerType: string
{
    case Direct = 'direct';
    case Search = 'search';
    case Social = 'social';
    case Referral = 'referral';

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
            self::Direct => 'Direct',
            self::Search => 'Search',
            self::Social => 'Social',
            self::Referral => 'Referral',
        };
    }

    /**
     * Display color token for the case.
     */
    public function color(): string
    {
        return match ($this) {
            self::Direct => 'gray',
            self::Search => 'green',
            self::Social => 'purple',
            self::Referral => 'amber',
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
        return self::Direct;
    }
}
