<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Tests\Feature\Bootstrap;

use FojleRabbiRabib\LaravelSpaAnalytics\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

class AboutCommandTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function section(): array
    {
        Artisan::call('about', ['--json' => true]);

        /** @var array<string, array<string, string>> $about */
        $about = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        return $about[Str::snake('SPA Analytics')];
    }

    public function test_the_about_command_lists_the_package_settings(): void
    {
        config()->set('spa-analytics.tracking.write_mode', 'queue');
        config()->set('spa-analytics.sessions.timeout_minutes', 45);
        config()->set('spa-analytics.identity.relink', true);

        $section = $this->section();

        $this->assertSame('ENABLED', $section['tracking']);
        $this->assertSame('queue', $section['write_mode']);
        $this->assertSame('45 minutes', $section['session_timeout']);
        $this->assertSame('ON', $section['re-linking']);
    }

    public function test_the_about_command_reports_disabled_tracking(): void
    {
        config()->set('spa-analytics.enabled', false);

        $section = $this->section();

        $this->assertSame('DISABLED', $section['tracking']);
        $this->assertSame('OFF', $section['re-linking']);
    }
}
