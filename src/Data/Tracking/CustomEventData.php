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
        public ?string $name,
        public ?float $value,
        public array $properties,
        public ?string $path,
        public ?string $language,
        public ?string $ip,
        public ?string $userAgent,
        public bool $isBot,
        public CarbonImmutable $occurredAt,
        public ?string $targetHost = null,
        public ?string $targetPath = null,
        public ?int $scrollPercent = null,
        public ?string $fileExtension = null,
        public ?int $engagedSeconds = null,
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
            name: isset($data['name']) ? (string) $data['name'] : null,
            value: isset($data['value']) ? (float) $data['value'] : null,
            properties: is_array($data['properties'] ?? null) ? $data['properties'] : [],
            path: isset($data['path']) ? (string) $data['path'] : null,
            language: isset($data['language']) ? (string) $data['language'] : null,
            ip: isset($data['ip']) ? (string) $data['ip'] : null,
            userAgent: isset($data['user_agent']) ? (string) $data['user_agent'] : null,
            isBot: (bool) $data['is_bot'],
            occurredAt: CarbonImmutable::parse($data['occurred_at']),
            targetHost: isset($data['target_host']) ? (string) $data['target_host'] : null,
            targetPath: isset($data['target_path']) ? (string) $data['target_path'] : null,
            scrollPercent: isset($data['scroll_percent']) ? (int) $data['scroll_percent'] : null,
            fileExtension: isset($data['file_extension']) ? (string) $data['file_extension'] : null,
            engagedSeconds: isset($data['engaged_seconds']) ? (int) $data['engaged_seconds'] : null,
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
            $this->targetHost,
            $this->targetPath,
            $this->scrollPercent,
            $this->fileExtension,
            $this->engagedSeconds,
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
            'target_host' => $this->targetHost,
            'target_path' => $this->targetPath,
            'scroll_percent' => $this->scrollPercent,
            'file_extension' => $this->fileExtension,
            'engaged_seconds' => $this->engagedSeconds,
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
