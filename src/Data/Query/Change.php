<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Data\Query;

final readonly class Change
{
    /**
     * @param  float  $absolute  The current value minus the previous one. For a rate (a ratio between 0 and 1) it is the difference of the two ratios.
     * @param  ?float  $percent  The absolute change as a percentage of the previous value's size; null when the previous value is 0, so there is nothing to compare with.
     */
    public function __construct(
        public float $absolute,
        public ?float $percent,
    ) {}

    public static function between(int|float $current, int|float $previous): self
    {
        $absolute = round($current - $previous, 6);

        return new self((float) $absolute, $previous == 0 ? null : round($absolute / abs($previous) * 100, 2));
    }

    /**
     * The change between two decimal strings with two places, worked out in whole cents so floats do not drift.
     */
    public static function money(string $current, string $previous): self
    {
        $current = (int) round((float) $current * 100);
        $previous = (int) round((float) $previous * 100);
        $absolute = $current - $previous;

        return new self($absolute / 100, $previous === 0 ? null : round($absolute / abs($previous) * 100, 2));
    }

    /**
     * The change of each named value.
     *
     * @param  array<string, int|float>  $current
     * @param  array<string, int|float>  $previous
     * @return array<string, self>
     */
    public static function all(array $current, array $previous): array
    {
        $changes = [];

        foreach ($current as $name => $value) {
            $changes[$name] = self::between($value, $previous[$name] ?? 0);
        }

        return $changes;
    }

    /**
     * @return array<string, float|null>
     */
    public function toArray(): array
    {
        return ['absolute' => $this->absolute, 'percent' => $this->percent];
    }
}
