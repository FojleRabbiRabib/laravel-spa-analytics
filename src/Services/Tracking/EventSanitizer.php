<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking;

class EventSanitizer
{
    private const MAX_PROPERTIES = 20;

    private const MAX_KEY_LENGTH = 64;

    private const MAX_STRING_LENGTH = 255;

    private const MAX_VALUE = 9999999999.99;

    /**
     * Whether the name is acceptable for a custom event or goal.
     */
    public function isValidName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $name) === 1;
    }

    /**
     * A goal value rounded to cents, or null when it is missing, negative, infinite or too large.
     */
    public function value(?float $value): ?float
    {
        if ($value === null || ! is_finite($value) || $value < 0 || $value > self::MAX_VALUE) {
            return null;
        }

        return round($value, 2);
    }

    /**
     * At most 20 scalar values under string keys of up to 64 characters; strings are cut to 255.
     *
     * @param  array<array-key, mixed>  $properties
     * @return array<string, scalar|null>
     */
    public function properties(array $properties): array
    {
        $clean = [];

        foreach ($properties as $key => $value) {
            if (count($clean) >= self::MAX_PROPERTIES) {
                break;
            }

            if (! is_string($key) || $key === '' || mb_strlen($key) > self::MAX_KEY_LENGTH) {
                continue;
            }

            if (is_string($value)) {
                $clean[$key] = mb_substr($value, 0, self::MAX_STRING_LENGTH);
            } elseif (is_int($value) || is_bool($value) || $value === null || (is_float($value) && is_finite($value))) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
