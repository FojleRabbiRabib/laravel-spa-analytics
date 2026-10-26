<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Unit\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\HeaderGeoLocator;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HeaderGeoLocatorRegionTest extends TestCase
{
    private function request(?string $country, ?string $region): Request
    {
        $request = Request::create('/');

        if ($country !== null) {
            $request->headers->set('CF-IPCountry', $country);
        }

        if ($region !== null) {
            $request->headers->set('CF-Region-Code', $region);
        }

        return $request;
    }

    private function locator(?string $regionHeader = 'CF-Region-Code'): HeaderGeoLocator
    {
        return new HeaderGeoLocator('CF-IPCountry', $regionHeader);
    }

    public function test_the_region_is_the_full_iso_code_made_from_the_country_and_the_header(): void
    {
        $location = $this->locator()->locate($this->request('us', 'ca'));

        $this->assertSame('US', $location->country);
        $this->assertSame('US-CA', $location->region);
    }

    public function test_a_header_that_already_holds_the_full_code_is_not_prefixed_twice(): void
    {
        $this->assertSame('US-CA', $this->locator()->locate($this->request('US', 'us-ca'))->region);
    }

    public function test_a_code_of_another_country_is_not_accepted_as_a_full_code(): void
    {
        $this->assertNull($this->locator()->locate($this->request('US', 'DE-BY'))->region);
    }

    public function test_a_region_without_a_known_country_is_dropped(): void
    {
        $location = $this->locator()->locate($this->request(null, 'CA'));

        $this->assertNull($location->country);
        $this->assertNull($location->region);
        $this->assertNull($this->locator()->locate($this->request('XX', 'CA'))->region);
    }

    public function test_there_is_no_region_without_a_configured_header_or_a_value(): void
    {
        $this->assertNull($this->locator(null)->locate($this->request('US', 'CA'))->region);
        $this->assertNull($this->locator('')->locate($this->request('US', 'CA'))->region);
        $this->assertNull($this->locator()->locate($this->request('US', null))->region);
        $this->assertSame('US', $this->locator(null)->locate($this->request('US', 'CA'))->country);
    }

    public function test_the_country_alone_still_works_through_the_old_method(): void
    {
        $this->assertSame('US', $this->locator()->country($this->request('US', 'CA')));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidRegions(): array
    {
        return [
            'too long' => ['CALI'],
            'empty' => [''],
            'spaces' => ['C A'],
            'punctuation' => ['C"'],
            'dash only' => ['-'],
        ];
    }

    #[DataProvider('invalidRegions')]
    public function test_it_ignores_values_that_are_not_a_subdivision_code(string $value): void
    {
        $this->assertNull($this->locator()->locate($this->request('US', $value))->region);
    }
}
