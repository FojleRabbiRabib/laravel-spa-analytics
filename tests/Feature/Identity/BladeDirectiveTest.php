<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Identity;

use FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking\ClientScript;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class BladeDirectiveTest extends TestCase
{
    private const SCRIPT = 'vendor/laravel-spa-analytics/client.js';

    protected function setUp(): void
    {
        parent::setUp();

        $this->removePublishedScript();
    }

    protected function tearDown(): void
    {
        $this->removePublishedScript();

        parent::tearDown();
    }

    /**
     * The Testbench skeleton lives under vendor/ and keeps published files between runs.
     */
    private function removePublishedScript(): void
    {
        @chmod(public_path(self::SCRIPT), 0644);
        @unlink(public_path(self::SCRIPT));
        @rmdir(public_path(dirname(self::SCRIPT)));
    }

    private function publishScript(string $contents): void
    {
        if (! is_dir(public_path(dirname(self::SCRIPT)))) {
            mkdir(public_path(dirname(self::SCRIPT)), 0777, true);
        }

        file_put_contents(public_path(self::SCRIPT), $contents);
    }

    private function render(): string
    {
        return Blade::render('@spaAnalytics', deleteCachedView: true);
    }

    private function scriptSrc(): string
    {
        preg_match('/<script src="([^"]+)"/', $this->render(), $match);

        return html_entity_decode($match[1]);
    }

    public function test_the_script_url_carries_a_twelve_character_hash_of_the_published_file(): void
    {
        $this->publishScript('console.log(1);');

        $this->assertMatchesRegularExpression('#^'.preg_quote(asset(self::SCRIPT), '#').'\?v=[0-9a-f]{12}$#', $this->scriptSrc());
    }

    public function test_the_script_url_changes_as_soon_as_the_published_file_changes(): void
    {
        $this->publishScript('console.log(1);');
        $before = $this->scriptSrc();

        $this->publishScript('console.log(2);');

        $this->assertNotSame($before, $this->scriptSrc());
    }

    public function test_the_script_url_stays_the_same_while_the_content_does_not_change(): void
    {
        $this->publishScript('console.log(1);');
        $first = $this->scriptSrc();

        touch(public_path(self::SCRIPT), time() + 60);

        $this->assertSame($first, $this->scriptSrc());
    }

    public function test_the_script_url_has_no_version_when_the_script_is_not_published(): void
    {
        $this->assertSame(asset(self::SCRIPT), $this->scriptSrc());
    }

    public function test_an_unreadable_published_script_never_breaks_the_page(): void
    {
        $this->publishScript('console.log(1);');
        chmod(public_path(self::SCRIPT), 0000);

        if (is_readable(public_path(self::SCRIPT))) {
            $this->markTestSkipped('The file stays readable for this user (root), so the failure cannot be reproduced.');
        }

        $this->assertSame(asset(self::SCRIPT), $this->scriptSrc());
    }

    public function test_the_version_follows_the_asset_url_of_the_app(): void
    {
        $this->publishScript('console.log(1);');
        URL::useAssetOrigin('https://cdn.example.com');

        $this->assertMatchesRegularExpression('#^https://cdn\.example\.com/'.preg_quote(self::SCRIPT, '#').'\?v=[0-9a-f]{12}$#', $this->scriptSrc());
    }

    public function test_the_csp_nonce_is_still_rendered_next_to_the_versioned_url(): void
    {
        $this->publishScript('console.log(1);');
        Vite::useCspNonce('abc123');

        $html = $this->render();

        $this->assertStringContainsString('nonce="abc123"', $html);
        $this->assertMatchesRegularExpression('/\?v=[0-9a-f]{12}"/', $html);
    }

    public function test_an_app_can_replace_the_script_service_through_the_container(): void
    {
        $this->app->instance(ClientScript::class, new class extends ClientScript
        {
            public function url(): string
            {
                return 'https://cdn.example.com/own.js?v=custom';
            }
        });

        $this->assertSame('https://cdn.example.com/own.js?v=custom', $this->scriptSrc());
    }

    public function test_the_script_is_not_hashed_at_all_when_tracking_is_disabled(): void
    {
        config()->set('spa-analytics.enabled', false);
        $this->app->instance(ClientScript::class, new class extends ClientScript
        {
            public function url(): string
            {
                throw new \RuntimeException('The script must not be hashed while tracking is disabled.');
            }
        });

        $this->assertSame('', trim($this->render()));
    }

    public function test_directive_renders_the_collector_script_with_endpoints(): void
    {
        $html = $this->render();

        $this->assertStringContainsString(self::SCRIPT, $html);
        $this->assertStringContainsString('data-handshake="'.route('spa-analytics.identity.handshake').'"', $html);
        $this->assertStringContainsString('data-identify="'.route('spa-analytics.identity.identify').'"', $html);
        $this->assertStringContainsString('data-collect="'.route('spa-analytics.collect').'"', $html);
    }

    public function test_directive_passes_the_configured_download_extensions_to_the_script(): void
    {
        config()->set('spa-analytics.collect.download_extensions', ['pdf', 'epub']);

        $this->assertStringContainsString('data-downloads="pdf,epub"', $this->render());
    }

    public function test_directive_renders_nothing_when_disabled(): void
    {
        config()->set('spa-analytics.enabled', false);

        $this->assertSame('', trim($this->render()));
    }

    public function test_assets_publish_tag_is_registered(): void
    {
        $this->assertArrayHasKey('spa-analytics-assets', ServiceProvider::$publishGroups);
    }
}
