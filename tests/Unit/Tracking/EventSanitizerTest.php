<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Unit\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\EventSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EventSanitizerTest extends TestCase
{
    /**
     * The names in tests/Fixtures/event-names.json, which the browser script's test reads too.
     *
     * @return array<string, array{string, bool}>
     */
    public static function names(): array
    {
        /** @var array{valid: array<int, string>, invalid: array<int, string>} $fixture */
        $fixture = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/event-names.json'), true, 512, JSON_THROW_ON_ERROR);
        $cases = [];

        foreach ([...$fixture['valid'], str_repeat('a', 128)] as $name) {
            $cases['valid '.json_encode($name)] = [$name, true];
        }

        foreach ([...$fixture['invalid'], str_repeat('a', 129)] as $name) {
            $cases['invalid '.json_encode($name)] = [$name, false];
        }

        return $cases;
    }

    #[DataProvider('names')]
    public function test_a_name_is_valid_exactly_when_the_shared_fixture_says_so(string $name, bool $valid): void
    {
        $this->assertSame($valid, (new EventSanitizer)->isValidName($name));
    }
}
