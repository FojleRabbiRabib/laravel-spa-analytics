<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\BotDetector;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Identity\VisitorIdentity;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\CustomEventData;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AnalyticsTracker
{
    private const MAX_PROPERTIES = 20;

    private const MAX_KEY_LENGTH = 64;

    private const MAX_STRING_LENGTH = 255;

    private const MAX_VALUE = 9999999999.99;

    private ?string $visitorId = null;

    private bool $scoped = false;

    public function __construct(
        private readonly EventWriter $writer,
        private readonly BotDetector $bots,
        private readonly PathNormalizer $paths,
        private readonly LanguageParser $languages,
    ) {}

    /**
     * Record a custom event for the current visitor.
     *
     * Invalid names are ignored and nothing is recorded when tracking is disabled or no visitor is known.
     * Properties keep at most 20 scalar values under string keys of up to 64 characters.
     *
     * @param  array<array-key, mixed>  $properties
     */
    public function track(string $name, array $properties = []): void
    {
        $this->record(EventType::Custom, $name, null, $properties);
    }

    /**
     * Record a goal for the current visitor, with an optional non-negative value such as revenue.
     *
     * @param  array<array-key, mixed>  $properties
     */
    public function goal(string $name, ?float $value = null, array $properties = []): void
    {
        $this->record(EventType::Goal, $name, $value, $properties);
    }

    /**
     * A tracker for an explicit visitor, for jobs, webhooks and commands.
     *
     * It never reads the current request, so the caller's IP, user agent and path are not attributed to the
     * visitor. A visitor id that is null or not a UUID makes the tracker record nothing.
     */
    public function for(?string $visitorId): static
    {
        $scoped = clone $this;
        $scoped->scoped = true;
        $scoped->visitorId = $visitorId !== null && Str::isUuid($visitorId) ? $visitorId : null;

        return $scoped;
    }

    /**
     * @param  array<array-key, mixed>  $properties
     */
    private function record(EventType $type, string $name, ?float $value, array $properties): void
    {
        try {
            if (! config('spa-analytics.enabled') || preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $name) !== 1) {
                return;
            }

            $visitorId = $this->scoped ? $this->visitorId : $this->currentVisitorId();

            if ($visitorId === null) {
                return;
            }

            $request = $this->scoped ? null : $this->currentRequest();
            $userAgent = $request?->userAgent();

            $this->writer->write(new CustomEventData(
                type: $type,
                visitorId: $visitorId,
                name: $name,
                value: $this->cleanValue($value),
                properties: $this->cleanProperties($properties),
                path: $request === null ? null : $this->paths->normalize($request->path()),
                language: $request === null ? null : $this->languages->parse($request),
                ip: $request?->ip(),
                userAgent: $userAgent === null ? null : mb_substr($userAgent, 0, 512),
                isBot: $userAgent !== null && $this->bots->isBot($userAgent),
                occurredAt: CarbonImmutable::now(),
            ));
        } catch (\Throwable $e) {
            Log::warning('spa-analytics: custom event failed', ['exception' => $e::class, 'code' => $e->getCode()]);
        }
    }

    private function currentVisitorId(): ?string
    {
        return app()->bound(VisitorIdentity::class) ? app(VisitorIdentity::class)->id : null;
    }

    private function currentRequest(): ?Request
    {
        $request = app()->bound('request') ? app('request') : null;

        return $request instanceof Request ? $request : null;
    }

    private function cleanValue(?float $value): ?float
    {
        if ($value === null || ! is_finite($value) || $value < 0 || $value > self::MAX_VALUE) {
            return null;
        }

        return round($value, 2);
    }

    /**
     * @param  array<array-key, mixed>  $properties
     * @return array<string, scalar|null>
     */
    private function cleanProperties(array $properties): array
    {
        $clean = [];

        foreach ($properties as $key => $value) {
            if (count($clean) >= self::MAX_PROPERTIES) {
                break;
            }

            if (! is_string($key) || $key === '' || mb_strlen($key) > self::MAX_KEY_LENGTH) {
                continue;
            }

            if (is_string($value)) {
                $clean[$key] = mb_substr($value, 0, self::MAX_STRING_LENGTH);
            } elseif (is_int($value) || is_bool($value) || $value === null || (is_float($value) && is_finite($value))) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
