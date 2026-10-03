<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Tracking;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

class FileDownloadCollectTest extends TestCase
{
    private const VISITOR = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-10 12:00:00');
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('spa-analytics.tracking.write_mode', 'sync');
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    private function collect(array $events): TestResponse
    {
        return $this->withCredentials()->withCookie('spa_analytics_vid', self::VISITOR)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0'])
            ->postJson(route('spa-analytics.collect'), ['events' => $events]);
    }

    private function download(string $url): TestResponse
    {
        return $this->collect([['kind' => 'download', 'path' => '/pricing', 'url' => $url]]);
    }

    public function test_a_file_on_the_site_is_stored_without_a_host_and_with_its_extension(): void
    {
        $this->download('http://localhost/files/Guide.PDF?signature=secret#page=2')->assertNoContent();

        $event = AnalyticsEvent::query()->sole();

        $this->assertSame(EventType::FileDownload, $event->type);
        $this->assertNull($event->target_host);
        $this->assertSame('/files/Guide.PDF', $event->target_path);
        $this->assertSame('pdf', $event->file_extension);
        $this->assertSame('/pricing', $event->path);
    }

    public function test_a_file_on_another_site_keeps_its_host(): void
    {
        $this->download('https://CDN.Example.org/a/report.zip?token=x')->assertNoContent();

        $event = AnalyticsEvent::query()->sole();

        $this->assertSame('cdn.example.org', $event->target_host);
        $this->assertSame('/a/report.zip', $event->target_path);
        $this->assertSame('zip', $event->file_extension);
    }

    public function test_a_link_without_a_listed_extension_is_kept_with_no_extension(): void
    {
        $this->download('http://localhost/export/data')->assertNoContent();

        $event = AnalyticsEvent::query()->sole();

        $this->assertSame('/export/data', $event->target_path);
        $this->assertNull($event->file_extension);
    }

    public function test_the_extension_comes_from_the_configured_list_not_from_the_client(): void
    {
        config()->set('spa-analytics.collect.download_extensions', ['psd']);

        $this->download('http://localhost/files/a.pdf')->assertNoContent();
        $this->download('http://localhost/files/b.PSD')->assertNoContent();

        $this->assertSame([null, 'psd'], AnalyticsEvent::query()->orderBy('id')->pluck('file_extension')->all());
    }

    public function test_the_configured_list_is_normalised_like_the_script_does(): void
    {
        config()->set('spa-analytics.collect.download_extensions', [' .PDF ']);

        $this->download('http://localhost/files/a.pdf')->assertNoContent();
        $this->download('http://localhost/.pdf')->assertNoContent();

        $this->assertSame(['pdf', null], AnalyticsEvent::query()->orderBy('id')->pluck('file_extension')->all());
    }

    public function test_a_client_sent_extension_is_ignored(): void
    {
        $this->collect([['kind' => 'download', 'path' => '/pricing', 'url' => 'http://localhost/files/a.pdf', 'extension' => 'exe', 'file_extension' => 'exe']])->assertNoContent();

        $this->assertSame('pdf', AnalyticsEvent::query()->sole()->file_extension);
    }

    public function test_non_http_links_are_not_downloads(): void
    {
        $this->download('mailto:me@example.org')->assertNoContent();
        $this->download('javascript:alert(1)')->assertNoContent();
        $this->download('/files/a.pdf')->assertNoContent();

        $this->assertSame(0, AnalyticsEvent::query()->count());
    }

    public function test_an_excluded_file_path_is_not_stored(): void
    {
        config()->set('spa-analytics.tracking.excluded_paths', ['private/*']);

        $this->download('http://localhost/private/contract.pdf')->assertNoContent();

        $event = AnalyticsEvent::query()->sole();

        $this->assertNull($event->target_path);
        $this->assertSame('pdf', $event->file_extension);
    }
}
