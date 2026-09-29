<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking;

class PathNormalizer
{
    private const MAX_LENGTH = 512;

    /**
     * Strip the query and fragment, ensure a single leading slash and cap the length.
     */
    public function normalize(string $path): string
    {
        $path = (string) preg_replace('/[?#].*$/s', '', $path);
        $path = '/'.ltrim((string) preg_replace('#/+#', '/', $path), '/');

        return mb_substr($path, 0, self::MAX_LENGTH);
    }
}
