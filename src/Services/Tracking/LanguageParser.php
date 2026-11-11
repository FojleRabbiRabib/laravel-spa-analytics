<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Support\Text;
use Illuminate\Http\Request;

class LanguageParser
{
    private const MAX_LENGTH = 16;

    /**
     * The first language tag of the Accept-Language header, or null when there is none or it is not a language tag.
     * Whole subtags are kept while they fit in 16 characters, and a single-letter subtag (an extension or private
     * use) ends the tag, so a cut never leaves a dangling dash.
     */
    public function parse(Request $request): ?string
    {
        $header = Text::strip((string) $request->headers->get('Accept-Language'));
        $tag = str_replace('_', '-', trim(explode(';', explode(',', $header)[0])[0]));

        if (preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{1,8})*$/D', $tag) !== 1) {
            return null;
        }

        $kept = [];
        $length = 0;

        foreach (explode('-', $tag) as $subtag) {
            $length += strlen($subtag) + ($kept === [] ? 0 : 1);

            if ($kept !== [] && (strlen($subtag) === 1 || $length > self::MAX_LENGTH)) {
                break;
            }

            $kept[] = $subtag;
        }

        return implode('-', $kept);
    }
}
