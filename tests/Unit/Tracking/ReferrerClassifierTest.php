<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Unit\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ReferrerType;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\ReferrerClassifier;
use PHPUnit\Framework\TestCase;

class ReferrerClassifierTest extends TestCase
{
    private function classifier(): ReferrerClassifier
    {
        return new ReferrerClassifier(['google.', 'bing.'], ['facebook.', 't.co']);
    }

    public function test_missing_or_unparsable_referrer_is_direct(): void
    {
        foreach ([null, '', 'not a url', 'https:///nohost'] as $referer) {
            $referrer = $this->classifier()->classify($referer, 'site.test');

            $this->assertNull($referrer->host);
            $this->assertSame(ReferrerType::Direct, $referrer->type);
        }
    }

    public function test_same_site_referrer_is_direct(): void
    {
        foreach (['https://site.test/a', 'https://www.site.test/a', 'https://SITE.test/'] as $referer) {
            $referrer = $this->classifier()->classify($referer, 'site.test');

            $this->assertNull($referrer->host);
            $this->assertSame(ReferrerType::Direct, $referrer->type);
        }
    }

    public function test_search_social_and_referral(): void
    {
        $search = $this->classifier()->classify('https://www.google.com/search?q=x', 'site.test');
        $this->assertSame('google.com', $search->host);
        $this->assertSame(ReferrerType::Search, $search->type);

        $social = $this->classifier()->classify('https://t.co/abc', 'site.test');
        $this->assertSame('t.co', $social->host);
        $this->assertSame(ReferrerType::Social, $social->type);

        $referral = $this->classifier()->classify('https://example.org/post', 'site.test');
        $this->assertSame('example.org', $referral->host);
        $this->assertSame(ReferrerType::Referral, $referral->type);
    }

    public function test_a_host_that_only_contains_a_pattern_is_a_referral(): void
    {
        $classifier = new ReferrerClassifier(['google.'], ['x.com', 't.co', 'facebook.']);

        foreach (['https://fedex.com/', 'https://wix.com/', 'https://microsoft.com/', 'https://t.com/', 'https://notgoogle.com/', 'https://notfacebook.com/'] as $referer) {
            $this->assertSame(ReferrerType::Referral, $classifier->classify($referer, 'site.test')->type, $referer);
        }
    }

    public function test_a_host_matches_its_pattern_and_the_subdomains_of_it(): void
    {
        $classifier = new ReferrerClassifier(['google.'], ['x.com', 't.co', 'facebook.']);

        foreach (['https://x.com/', 'https://mobile.x.com/', 'https://t.co/a', 'https://m.facebook.com/', 'https://l.facebook.com/l.php'] as $referer) {
            $this->assertSame(ReferrerType::Social, $classifier->classify($referer, 'site.test')->type, $referer);
        }

        $this->assertSame(ReferrerType::Search, $classifier->classify('https://mail.google.co.uk/', 'site.test')->type);
    }
}
