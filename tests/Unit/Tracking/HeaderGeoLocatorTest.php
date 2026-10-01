<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Unit\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\HeaderGeoLocator;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HeaderGeoLocatorTest extends TestCase
{
    private function request(?string $value, string $header = 'CF-IPCountry'): Request
    {
        $request = Request::create('/');

        if ($value !== null) {
            $request->headers->set($header, $value);
        }

        return $request;
    }

    public function test_it_reads_the_configured_header_and_uppercases_it(): void
    {
        $this->assertSame('BD', (new HeaderGeoLocator('CF-IPCountry'))->country($this->request('bd')));
    }

    public function test_it_finds_nothing_without_a_configured_header(): void
    {
        $this->assertNull((new HeaderGeoLocator(null))->country($this->request('BD')));
        $this->assertNull((new HeaderGeoLocator(''))->country($this->request('BD')));
    }

    public function test_it_finds_nothing_when_the_header_is_absent(): void
    {
        $this->assertNull((new HeaderGeoLocator('CF-IPCountry'))->country($this->request(null)));
    }

    public function test_the_header_name_is_the_one_configured(): void
    {
        $locator = new HeaderGeoLocator('CloudFront-Viewer-Country');

        $this->assertSame('DE', $locator->country($this->request('DE', 'CloudFront-Viewer-Country')));
        $this->assertNull($locator->country($this->request('DE')));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidValues(): array
    {
        return [
            'tor' => ['T1'],
            'unknown' => ['XX'],
            'unknown z' => ['ZZ'],
            'anonymous proxy' => ['A1'],
            'satellite' => ['A2'],
            'too long' => ['BGD'],
            'one letter' => ['B'],
            'digits' => ['12'],
            'empty' => [''],
            'injection' => ['B"'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_it_ignores_values_that_are_not_a_country(string $value): void
    {
        $this->assertNull((new HeaderGeoLocator('CF-IPCountry'))->country($this->request($value)));
    }
}
