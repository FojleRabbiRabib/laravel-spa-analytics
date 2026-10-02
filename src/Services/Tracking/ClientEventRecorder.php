<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Services\Tracking;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Contracts\BotDetector;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\CustomEventData;
use FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking\PageViewData;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ClientEventKind;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ClientEventRecorder
{
    private const MAX_AGE_MS = 300000;

    private const SCROLL_MILESTONES = [25, 50, 75, 100];

    public function __construct(
        private readonly PathNormalizer $paths,
        private readonly UtmParser $utm,
        private readonly BotDetector $bots,
        private readonly ReferrerClassifier $referrers,
        private readonly EventWriter $writer,
        private readonly LanguageParser $languages,
        private readonly AudienceResolver $audience,
        private readonly PageViewGuard $guard,
        private readonly EventSanitizer $sanitizer,
    ) {}

    /**
     * Record the events the browser reported, in order, as if the visitor's own request had made them.
     *
     * IP address, user agent, language and audience come from the collect request itself, so they describe the
     * browser that sent it; the path of each event comes from the payload, is normalised like a request path and
     * is dropped when it matches an excluded path. Each event's time is the request time minus the age the client
     * reports, limited to five minutes, so a batch keeps its order and never lands in the future.
     *
     * @param  array<int, array<string, mixed>>  $events  Events already validated by the collect request.
     */
    public function record(Request $request, string $visitorId, array $events): void
    {
        $userAgent = $request->userAgent();
        $context = [
            'visitorId' => $visitorId,
            'language' => $this->languages->parse($request),
            'ip' => $request->ip(),
            'userAgent' => $userAgent === null ? null : mb_substr($userAgent, 0, 512),
            'isBot' => $this->bots->isBot($userAgent),
        ];
        $now = CarbonImmutable::now();

        foreach ($events as $event) {
            try {
                $this->recordOne($request, $context, $event, $now);
            } catch (\Throwable $e) {
                Log::warning('spa-analytics: client event failed', ['exception' => $e::class, 'code' => $e->getCode()]);
            }
        }
    }

    /**
     * @param  array{visitorId: string, language: ?string, ip: ?string, userAgent: ?string, isBot: bool}  $context
     * @param  array<string, mixed>  $event
     */
    private function recordOne(Request $request, array $context, array $event, CarbonImmutable $now): void
    {
        $kind = ClientEventKind::from((string) $event['kind']);
        $path = $this->paths->normalize((string) $event['path']);

        if ($this->isExcluded($path)) {
            return;
        }

        $age = min(max(is_numeric($event['age'] ?? null) ? (int) $event['age'] : 0, 0), self::MAX_AGE_MS);
        $occurredAt = $now->subMilliseconds($age);

        $data = match ($kind) {
            ClientEventKind::PageView => $this->pageView($request, $context, $event, $path, $occurredAt),
            ClientEventKind::Outbound => $this->outbound($request, $context, $event, $path, $occurredAt),
            ClientEventKind::Scroll => $this->scroll($context, $event, $path, $occurredAt),
            ClientEventKind::Event => $this->custom(EventType::Custom, $context, $event, $path, $occurredAt),
            ClientEventKind::Goal => $this->custom(EventType::Goal, $context, $event, $path, $occurredAt),
        };

        if ($data !== null) {
            $this->writer->write($data);
        }
    }

    /**
     * @param  array{visitorId: string, language: ?string, ip: ?string, userAgent: ?string, isBot: bool}  $context
     * @param  array<string, mixed>  $event
     */
    private function pageView(Request $request, array $context, array $event, string $path, CarbonImmutable $occurredAt): ?PageViewData
    {
        if (! $this->guard->claim($context['visitorId'], $path)) {
            return null;
        }

        $referrer = $this->referrers->classify($this->text($event['referrer'] ?? null), $request->getHost());

        return new PageViewData(
            type: EventType::PageView,
            visitorId: $context['visitorId'],
            path: $path,
            status: null,
            referrerHost: $referrer->host,
            referrerType: $referrer->type,
            utm: $this->utm->parse([]),
            language: $context['language'],
            ip: $context['ip'],
            userAgent: $context['userAgent'],
            isBot: $context['isBot'],
            occurredAt: $occurredAt,
            audience: $this->audience->resolve($request, $context['userAgent']),
        );
    }

    /**
     * @param  array{visitorId: string, language: ?string, ip: ?string, userAgent: ?string, isBot: bool}  $context
     * @param  array<string, mixed>  $event
     */
    private function outbound(Request $request, array $context, array $event, string $path, CarbonImmutable $occurredAt): ?CustomEventData
    {
        $parts = parse_url($this->text($event['url'] ?? null) ?? '');
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || $this->sameSite($host, $request->getHost())) {
            return null;
        }

        $targetPath = $this->paths->normalize((string) ($parts['path'] ?? '/'));

        return $this->clientEvent(EventType::OutboundClick, $context, $path, $occurredAt, targetHost: mb_substr($host, 0, 255), targetPath: $this->isExcluded($targetPath) ? null : $targetPath);
    }

    /**
     * @param  array{visitorId: string, language: ?string, ip: ?string, userAgent: ?string, isBot: bool}  $context
     * @param  array<string, mixed>  $event
     */
    private function scroll(array $context, array $event, string $path, CarbonImmutable $occurredAt): ?CustomEventData
    {
        $percent = is_numeric($event['percent'] ?? null) ? (int) $event['percent'] : 0;

        return in_array($percent, self::SCROLL_MILESTONES, true)
            ? $this->clientEvent(EventType::ScrollDepth, $context, $path, $occurredAt, scrollPercent: $percent)
            : null;
    }

    /**
     * @param  array{visitorId: string, language: ?string, ip: ?string, userAgent: ?string, isBot: bool}  $context
     * @param  array<string, mixed>  $event
     */
    private function custom(EventType $type, array $context, array $event, string $path, CarbonImmutable $occurredAt): ?CustomEventData
    {
        $name = $event['name'] ?? null;

        if (! is_string($name) || ! $this->sanitizer->isValidName($name)) {
            return null;
        }

        return $this->clientEvent(
            $type,
            $context,
            $path,
            $occurredAt,
            name: $name,
            value: $type->is(EventType::Goal) && is_numeric($event['value'] ?? null) ? $this->sanitizer->value((float) $event['value']) : null,
            properties: is_array($event['properties'] ?? null) ? $this->sanitizer->properties($event['properties']) : [],
        );
    }

    /**
     * @param  array{visitorId: string, language: ?string, ip: ?string, userAgent: ?string, isBot: bool}  $context
     * @param  array<string, scalar|null>  $properties
     */
    private function clientEvent(EventType $type, array $context, string $path, CarbonImmutable $occurredAt, ?string $name = null, ?float $value = null, array $properties = [], ?string $targetHost = null, ?string $targetPath = null, ?int $scrollPercent = null): CustomEventData
    {
        return new CustomEventData(
            type: $type,
            visitorId: $context['visitorId'],
            name: $name,
            value: $value,
            properties: $properties,
            path: $path,
            language: $context['language'],
            ip: $context['ip'],
            userAgent: $context['userAgent'],
            isBot: $context['isBot'],
            occurredAt: $occurredAt,
            targetHost: $targetHost,
            targetPath: $targetPath,
            scrollPercent: $scrollPercent,
        );
    }

    /**
     * A string field cut to 2048 characters, or null when it is missing or not a string.
     */
    private function text(mixed $value): ?string
    {
        return is_string($value) ? mb_substr($value, 0, 2048) : null;
    }

    /**
     * Whether the path matches one of the excluded path patterns; the patterns are written without a leading slash.
     */
    private function isExcluded(string $path): bool
    {
        return Str::is((array) config('spa-analytics.tracking.excluded_paths'), ltrim($path, '/'));
    }

    private function sameSite(string $host, string $ownHost): bool
    {
        return preg_replace('/^www\./', '', $host) === preg_replace('/^www\./', '', strtolower($ownHost));
    }
}
