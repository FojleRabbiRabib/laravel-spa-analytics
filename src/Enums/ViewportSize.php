<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Enums;

enum ViewportSize: string
{
    case Xs = 'xs';
    case Sm = 'sm';
    case Md = 'md';
    case Lg = 'lg';
    case Xl = 'xl';

    private const MAX_WIDTH = 10000;

    /**
     * Whether this case equals the given case.
     */
    public function is(self $size): bool
    {
        return $this === $size;
    }

    /**
     * Human-readable name of the case.
     */
    public function label(): string
    {
        return match ($this) {
            self::Xs => 'Under 576 px',
            self::Sm => '576 to 767 px',
            self::Md => '768 to 991 px',
            self::Lg => '992 to 1199 px',
            self::Xl => '1200 px and wider',
        };
    }

    /**
     * Display color token for the case.
     */
    public function color(): string
    {
        return match ($this) {
            self::Xs, self::Sm => 'green',
            self::Md => 'purple',
            self::Lg, self::Xl => 'blue',
        };
    }

    /**
     * The size class of a window width in pixels, or null when the width is not a plausible one.
     */
    public static function fromWidth(int $width): ?self
    {
        return match (true) {
            $width < 1 || $width > self::MAX_WIDTH => null,
            $width < 576 => self::Xs,
            $width < 768 => self::Sm,
            $width < 992 => self::Md,
            $width < 1200 => self::Lg,
            default => self::Xl,
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
            fn (self $size): array => ['value' => $size->value, 'label' => $size->label()],
            self::cases(),
        );
    }

    /**
     * The case for a case or its backing string.
     */
    public static function coerce(self|string $size): self
    {
        return $size instanceof self ? $size : self::from($size);
    }

    /**
     * The case used when none is specified.
     */
    public static function default(): self
    {
        return self::Md;
    }
}
