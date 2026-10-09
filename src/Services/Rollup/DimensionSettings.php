<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Rollup;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\RollupDimension;

class DimensionSettings
{
    /**
     * The dimensions the app turned off with rollups.disabled_dimensions.
     *
     * Names that are not a dimension and the dimensions Stats cannot work without are left out, so the builder, the
     * purge and the reports all agree on the same list.
     *
     * @return list<RollupDimension>
     */
    public function disabled(): array
    {
        $disabled = [];

        foreach ($this->listed() as $name) {
            $dimension = RollupDimension::tryFrom($name);

            if ($dimension !== null && ! in_array($dimension, RollupDimension::REQUIRED, true) && ! in_array($dimension, $disabled, true)) {
                $disabled[] = $dimension;
            }
        }

        return $disabled;
    }

    /**
     * Whether the dimension is built and can be read; the required ones always are.
     */
    public function isEnabled(RollupDimension $dimension): bool
    {
        return ! in_array($dimension, $this->disabled(), true);
    }

    /**
     * Entries of rollups.disabled_dimensions that are ignored, with the reason, for the rollup command to report.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        foreach ($this->listed() as $name) {
            $dimension = RollupDimension::tryFrom($name);

            if ($dimension === null) {
                $problems[] = "'{$name}' is not a rollup dimension";
            } elseif (in_array($dimension, RollupDimension::REQUIRED, true)) {
                $problems[] = "'{$name}' cannot be disabled because Stats needs it";
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * @return list<string>
     */
    private function listed(): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $name): string => is_string($name) ? trim($name) : '',
            (array) config('spa-analytics.rollups.disabled_dimensions', []),
        ), fn (string $name): bool => $name !== ''));
    }
}
