<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Unit\Support;

use FojleRabbiRabib\LaravelSpaAnalytics\Support\Text;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TextTest extends TestCase
{
    public function test_strip_removes_invalid_utf8_and_control_characters_but_keeps_the_rest(): void
    {
        $this->assertSame('ab cd', Text::strip("a\0b\x07 c\x7fd"));
        $this->assertTrue(mb_check_encoding(Text::strip("bad\xc3\x28 bytes"), 'UTF-8'));
        $this->assertSame('bücher/ü', Text::strip('bücher/ü'));
        $this->assertSame('line1 line2 end', Text::strip("line1\nline2\tend"));
    }

    public function test_clean_scrubs_trims_and_cuts_in_that_order(): void
    {
        $this->assertSame('abc', Text::clean("  abc\0  ", 10));
        $this->assertSame('ab', Text::clean('abcdef', 2));
        $this->assertSame(5, mb_strlen((string) Text::clean(str_repeat('é', 50)."\xff", 5)));
    }

    public function test_clean_gives_null_for_anything_that_is_not_a_non_empty_string(): void
    {
        $this->assertNull(Text::clean(null, 10));
        $this->assertNull(Text::clean(['a'], 10));
        $this->assertNull(Text::clean(12, 10));
        $this->assertNull(Text::clean("  \0 ", 10));
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function hosts(): array
    {
        return [
            'plain' => ['example.org', 'example.org'],
            'uppercase is lowered' => ['Google.COM', 'google.com'],
            'idn' => ['Bücher.de', 'bücher.de'],
            'combining vowel signs' => ['বাংলা.com', 'বাংলা.com'],
            'decomposed letter' => ["bu\u{0308}cher.de", "bu\u{0308}cher.de"],
            'joiner in a persian name' => ["می\u{200c}خواهم.ir", "می\u{200c}خواهم.ir"],
            'trailing dot' => ['example.com.', 'example.com'],
            'underscore' => ['my_app.example.com', 'my_app.example.com'],
            'ipv6 without the port' => ['[::1]', '[::1]'],
            'punycode' => ['xn--bcher-kva.de', 'xn--bcher-kva.de'],
            'ipv4' => ['203.0.113.9', '203.0.113.9'],
            'ipv6' => ['[2001:db8::1]', '[2001:db8::1]'],
            'markup' => ['<script>alert(1)<', null],
            'quote' => ['a"b.com', null],
            'space' => ['a b.com', null],
            'leading dash' => ['-a.com', null],
            'empty' => ['', null],
            'control' => ["a\0b.com", null],
            'too long' => [str_repeat('a', 256), null],
            'longest allowed' => [str_repeat('a', 255), str_repeat('a', 255)],
        ];
    }

    #[DataProvider('hosts')]
    public function test_host_is_lowercased_and_only_a_valid_host_name_is_kept(string $host, ?string $expected): void
    {
        $this->assertSame($expected, Text::host($host));
    }

    public function test_host_gives_null_for_null(): void
    {
        $this->assertNull(Text::host(null));
    }
}
