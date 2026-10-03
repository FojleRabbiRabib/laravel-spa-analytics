<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Database\Factories;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ReferrerType;
use FojleRabbiRabib\LaravelSpaAnalytics\Models\AnalyticsEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyticsEvent>
 */
class AnalyticsEventFactory extends Factory
{
    protected $model = AnalyticsEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => EventType::PageView,
            'visitor_id' => fake()->uuid(),
            'path' => '/'.fake()->slug(2),
            'status' => 200,
            'referrer_host' => null,
            'referrer_type' => ReferrerType::Direct,
            'language' => 'en',
            'ip' => fake()->ipv4(),
            'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0',
            'is_bot' => false,
            'occurred_at' => now(),
        ];
    }

    /**
     * A custom event: named, without a path or status.
     */
    public function custom(): static
    {
        return $this->state(fn (): array => [
            'type' => EventType::Custom,
            'name' => fake()->slug(2),
            'path' => null,
            'status' => null,
        ]);
    }

    /**
     * A goal event: named, without a path or status.
     */
    public function goal(): static
    {
        return $this->state(fn (): array => [
            'type' => EventType::Goal,
            'name' => fake()->slug(2),
            'path' => null,
            'status' => null,
        ]);
    }

    /**
     * An outbound click: a target host and path on the page it happened on.
     */
    public function outboundClick(): static
    {
        return $this->state(fn (): array => [
            'type' => EventType::OutboundClick,
            'target_host' => fake()->domainName(),
            'target_path' => '/'.fake()->slug(2),
            'status' => null,
        ]);
    }

    /**
     * A scroll depth milestone reached on the page.
     */
    public function scrollDepth(int $percent = 50): static
    {
        return $this->state(fn (): array => [
            'type' => EventType::ScrollDepth,
            'scroll_percent' => $percent,
            'status' => null,
        ]);
    }

    /**
     * A file download: the path of the file (and its host when it is on another site) on the page it happened on.
     */
    public function download(string $path = '/files/guide.pdf', ?string $extension = 'pdf', ?string $host = null): static
    {
        return $this->state(fn (): array => [
            'type' => EventType::FileDownload,
            'target_host' => $host,
            'target_path' => $path,
            'file_extension' => $extension,
            'status' => null,
        ]);
    }

    /**
     * Mark the event as bot traffic.
     */
    public function bot(): static
    {
        return $this->state(fn (): array => [
            'is_bot' => true,
            'user_agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)',
        ]);
    }
}
