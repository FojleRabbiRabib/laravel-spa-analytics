<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Data\Tracking;

use Carbon\CarbonImmutable;
use FojleRabbiRabib\LaravelSpaAnalytics\Enums\EventType;

final readonly class CustomEventData
{
    /**
     * @param  array<string, scalar|null>  $properties
     */
    public function __construct(
        public EventType $type,
        public string $visitorId,
        public string $name,
        public ?float $value,
        public array $properties,
        public ?string $path,
        public ?string $language,
        public ?string $ip,
        public ?string $userAgent,
        public bool $isBot,
        public CarbonImmutable $occurredAt,
    ) {
        if ($type->is(EventType::PageView)) {
            throw new \InvalidArgumentException('A custom event cannot use the page view type.');
        }
    }

    /**
     * @param  array<string, mixed>  $data  Column-keyed values, as produced by toArray().
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: EventType::coerce($data['type']),
            visitorId: (string) $data['visitor_id'],
            name: (string) $data['name'],
            value: isset($data['value']) ? (float) $data['value'] : null,
            properties: is_array($data['properties'] ?? null) ? $data['properties'] : [],
            path: isset($data['path']) ? (string) $data['path'] : null,
            language: isset($data['language']) ? (string) $data['language'] : null,
            ip: isset($data['ip']) ? (string) $data['ip'] : null,
            userAgent: isset($data['user_agent']) ? (string) $data['user_agent'] : null,
            isBot: (bool) $data['is_bot'],
            occurredAt: CarbonImmutable::parse($data['occurred_at']),
        );
    }

    /**
     * Return a copy of this event attributed to a different visitor id.
     */
    public function withVisitorId(string $visitorId): self
    {
        return new self(
            $this->type,
            $visitorId,
            $this->name,
            $this->value,
            $this->properties,
            $this->path,
            $this->language,
            $this->ip,
            $this->userAgent,
            $this->isBot,
            $this->occurredAt,
        );
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
            'name' => $this->name,
            'value' => $this->value,
            'properties' => $this->properties === [] ? null : $this->properties,
            'path' => $this->path,
            'status' => null,
            'language' => $this->language,
            'ip' => $this->ip,
            'user_agent' => $this->userAgent,
            'is_bot' => $this->isBot,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
