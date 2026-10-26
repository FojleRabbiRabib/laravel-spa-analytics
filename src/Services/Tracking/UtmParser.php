<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Support\Text;

class UtmParser
{
    private const KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    private const MAX_LENGTH = 255;

    /**
     * Read the five UTM values from a query array, null when absent or not a plain string.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, ?string>
     */
    public function parse(array $query): array
    {
        $utm = [];

        foreach (self::KEYS as $key) {
            $utm[$key] = Text::clean($query[$key] ?? null, self::MAX_LENGTH);
        }

        return $utm;
    }
}
