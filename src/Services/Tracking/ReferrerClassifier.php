<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\Referrer;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ReferrerType;

class ReferrerClassifier
{
    /**
     * @param  array<int, string>  $searchHosts  Case-insensitive host substrings.
     * @param  array<int, string>  $socialHosts  Case-insensitive host substrings.
     */
    public function __construct(
        private readonly array $searchHosts,
        private readonly array $socialHosts,
    ) {}

    /**
     * Classify a Referer header; missing, unparsable and same-site referrers are direct.
     */
    public function classify(?string $referer, string $ownHost): Referrer
    {
        $host = is_string($referer) && $referer !== '' ? parse_url($referer, PHP_URL_HOST) : null;

        if (! is_string($host) || $host === '') {
            return new Referrer(null, ReferrerType::Direct);
        }

        $host = $this->withoutWww($host);

        if ($host === $this->withoutWww($ownHost)) {
            return new Referrer(null, ReferrerType::Direct);
        }

        return new Referrer($host, match (true) {
            $this->matches($host, $this->searchHosts) => ReferrerType::Search,
            $this->matches($host, $this->socialHosts) => ReferrerType::Social,
            default => ReferrerType::Referral,
        });
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function matches(string $host, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($host, strtolower($needle))) {
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
