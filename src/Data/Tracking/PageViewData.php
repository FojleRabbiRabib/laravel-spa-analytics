<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\ReferrerType;

final readonly class PageViewData
{
    /**
     * @param  array<string, ?string>  $utm  Keys utm_source, utm_medium, utm_campaign, utm_term, utm_content.
     */
    public function __construct(
        public EventType $type,
        public string $visitorId,
        public string $path,
        public ?int $status,
        public ?string $referrerHost,
        public ReferrerType $referrerType,
        public array $utm,
        public ?string $language,
        public ?string $ip,
        public ?string $userAgent,
        public bool $isBot,
        public CarbonImmutable $occurredAt,
        public AudienceData $audience = new AudienceData,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Column-keyed values, as produced by toArray() or toPayload().
     */
    public static function fromArray(array $data): self
    {
        $type = $data['type'] instanceof EventType ? $data['type'] : EventType::from((string) $data['type']);
        $referrerType = $data['referrer_type'] instanceof ReferrerType
            ? $data['referrer_type']
            : ReferrerType::from((string) $data['referrer_type']);

        $utm = [];

        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $key) {
            $utm[$key] = isset($data[$key]) ? (string) $data[$key] : null;
        }

        return new self(
            type: $type,
            visitorId: (string) $data['visitor_id'],
            path: (string) $data['path'],
            status: isset($data['status']) ? (int) $data['status'] : null,
            referrerHost: isset($data['referrer_host']) ? (string) $data['referrer_host'] : null,
            referrerType: $referrerType,
            utm: $utm,
            language: isset($data['language']) ? (string) $data['language'] : null,
            ip: isset($data['ip']) ? (string) $data['ip'] : null,
            userAgent: isset($data['user_agent']) ? (string) $data['user_agent'] : null,
            isBot: (bool) $data['is_bot'],
            occurredAt: CarbonImmutable::parse($data['occurred_at']),
            audience: AudienceData::fromArray($data),
        );
    }

    /**
     * Return a copy of this page view attributed to a different visitor id.
     */
    public function withVisitorId(string $visitorId): self
    {
        return new self(
            $this->type,
            $visitorId,
            $this->path,
            $this->status,
            $this->referrerHost,
            $this->referrerType,
            $this->utm,
            $this->language,
            $this->ip,
            $this->userAgent,
            $this->isBot,
            $this->occurredAt,
            $this->audience,
        );
    }

    /**
     * Event columns plus the audience columns, for carrying the whole page view through a queued job.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [...$this->toArray(), ...$this->audience->toArray()];
    }

    /**
     * Column-keyed attributes ready for the events table.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'visitor_id' => $this->visitorId,
            'path' => $this->path,
            'status' => $this->status,
            'referrer_host' => $this->referrerHost,
            'referrer_type' => $this->referrerType,
            ...$this->utm,
            'language' => $this->language,
            'ip' => $this->ip,
            'user_agent' => $this->userAgent,
            'is_bot' => $this->isBot,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
