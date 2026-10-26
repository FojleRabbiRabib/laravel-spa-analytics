<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Unit\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Exceptions\GeoDatabaseUnavailable;
use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\MaxMindGeoLookup;
use Illuminate\Http\Request;
use MaxMind\Db\Reader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MaxMindGeoLookupTest extends TestCase
{
    private function request(string $ip): Request
    {
        return Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => $ip]);
    }

    private function lookup(mixed $record, int $calls = 1): MaxMindGeoLookup
    {
        $reader = $this->createMock(Reader::class);
        $reader->expects($this->exactly($calls))->method('get')->willReturn($record);

        return new MaxMindGeoLookup('unused.mmdb', $reader);
    }

    public function test_a_city_record_gives_the_country_and_the_full_region_code(): void
    {
        $location = $this->lookup([
            'country' => ['iso_code' => 'US'],
            'subdivisions' => [['iso_code' => 'CA'], ['iso_code' => 'X']],
        ])->locate($this->request('8.8.8.8'));

        $this->assertSame('US', $location->country);
        $this->assertSame('US-CA', $location->region);
    }

    public function test_a_country_record_has_no_region(): void
    {
        $location = $this->lookup(['country' => ['iso_code' => 'DE']])->locate($this->request('8.8.8.8'));

        $this->assertSame('DE', $location->country);
        $this->assertNull($location->region);
    }

    public function test_an_address_that_is_not_in_the_database_gives_nothing(): void
    {
        $location = $this->lookup(null)->locate($this->request('8.8.8.8'));

        $this->assertNull($location->country);
        $this->assertNull($location->region);
    }

    public function test_a_record_without_a_country_gives_nothing_even_with_a_subdivision(): void
    {
        $location = $this->lookup(['subdivisions' => [['iso_code' => 'CA']]])->locate($this->request('8.8.8.8'));

        $this->assertNull($location->country);
        $this->assertNull($location->region);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function addressesToSkip(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'private' => ['192.168.1.20'],
            'private ipv4 range' => ['10.0.0.5'],
            'ipv6 loopback' => ['::1'],
        ];
    }

    #[DataProvider('addressesToSkip')]
    public function test_private_and_local_addresses_never_reach_the_database(string $ip): void
    {
        $location = $this->lookup(['country' => ['iso_code' => 'US']], 0)->locate($this->request($ip));

        $this->assertNull($location->country);
    }

    public function test_an_unreadable_database_file_throws_a_named_exception_without_the_address(): void
    {
        $lookup = new MaxMindGeoLookup('/nonexistent/GeoLite2-City.mmdb');

        try {
            $lookup->locate($this->request('8.8.8.8'));
            $this->fail('Expected GeoDatabaseUnavailable.');
        } catch (GeoDatabaseUnavailable $e) {
            $this->assertStringContainsString('GeoLite2-City.mmdb', $e->getMessage());
            $this->assertStringNotContainsString('8.8.8.8', $e->getMessage());
        }
    }

    public function test_a_subdivision_without_a_code_gives_the_country_only(): void
    {
        $location = $this->lookup(['country' => ['iso_code' => 'US'], 'subdivisions' => [['names' => ['en' => 'California']]]])
            ->locate($this->request('8.8.8.8'));

        $this->assertSame('US', $location->country);
        $this->assertNull($location->region);
    }

    public function test_the_reader_is_kept_between_lookups(): void
    {
        $lookup = $this->lookup(['country' => ['iso_code' => 'US']], 2);

        $lookup->locate($this->request('8.8.8.8'));

        $this->assertSame('US', $lookup->locate($this->request('1.1.1.1'))->country);
    }

    public function test_a_failed_open_is_remembered_and_not_retried(): void
    {
        $lookup = new MaxMindGeoLookup('/nonexistent/GeoLite2-City.mmdb');
        $errors = [];

        foreach ([1, 2] as $_) {
            try {
                $lookup->locate($this->request('8.8.8.8'));
            } catch (GeoDatabaseUnavailable $e) {
                $errors[] = $e;
            }
        }

        $this->assertCount(2, $errors);
        $this->assertSame($errors[0], $errors[1]);
    }

    public function test_the_database_is_not_opened_when_the_lookup_is_built(): void
    {
        $this->assertInstanceOf(MaxMindGeoLookup::class, new MaxMindGeoLookup('/nonexistent/GeoLite2-City.mmdb'));
    }
}
