<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\Referrer;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ReferrerType;
use FojleRabbiRabib\LaravelSpaAnalytics\Support\Text;

class ReferrerClassifier
{
    /**
     * @param  array<int, string>  $searchHosts  Case-insensitive domain patterns, see matches().
     * @param  array<int, string>  $socialHosts  Case-insensitive domain patterns, see matches().
     */
    public function __construct(
        private readonly array $searchHosts,
        private readonly array $socialHosts,
    ) {}

    /**
     * Classify a Referer header; missing, unparsable, invalid-host and same-site referrers are direct.
     */
    public function classify(?string $referer, string $ownHost): Referrer
    {
        $host = is_string($referer) && $referer !== '' ? parse_url($referer, PHP_URL_HOST) : null;

        if (! is_string($host) || $host === '') {
            return new Referrer(null, ReferrerType::Direct);
        }

        $host = Text::host($this->withoutWww($host));

        if ($host === null || $host === $this->withoutWww($ownHost)) {
            return new Referrer(null, ReferrerType::Direct);
        }

        return new Referrer($host, match (true) {
            $this->matches($host, $this->searchHosts) => ReferrerType::Search,
            $this->matches($host, $this->socialHosts) => ReferrerType::Social,
            default => ReferrerType::Referral,
        });
    }

    /**
     * A pattern matches from the start of the host or after a dot. One that ends in a dot (`google.`) matches any
     * suffix after it; any other (`x.com`) must also end at the host or at a dot, so `fedex.com` and `t.com` never match.
     *
     * @param  array<int, string>  $needles
     */
    private function matches(string $host, array $needles): bool
    {
        foreach ($needles as $needle) {
            $needle = strtolower($needle);

            if ($needle !== '' && preg_match('/(?:^|\.)'.preg_quote($needle, '/').(str_ends_with($needle, '.') ? '' : '(?:\.|$)').'/', $host) === 1) {
                return true;
            }
        }

        return false;
    }

    private function withoutWww(string $host): string
    {
        return preg_replace('/^www\./', '', strtolower($host)) ?? strtolower($host);
    }
}
