<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking;

use Illuminate\Http\Request;

class LanguageParser
{
    private const MAX_LENGTH = 16;

    /**
     * The first language tag of the Accept-Language header, or null when there is none.
     */
    public function parse(Request $request): ?string
    {
        $header = (string) $request->headers->get('Accept-Language');
        $tag = trim(explode(';', explode(',', $header)[0])[0]);

        return $tag === '' ? null : mb_substr($tag, 0, self::MAX_LENGTH);
    }
}
