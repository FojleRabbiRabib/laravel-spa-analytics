<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Data\Query;

use FojleRabbiRabib\LaravelSpaAnalytics\Enums\FunnelStepType;

final readonly class FunnelStep
{
    /**
     * @throws \InvalidArgumentException When the value is empty.
     */
    public function __construct(
        public FunnelStepType $type,
        public string $value,
        public string $label,
    ) {
        if ($value === '') {
            throw new \InvalidArgumentException('A funnel step needs a value.');
        }
    }

    /**
     * A step from a decoded definition such as `['type' => 'goal', 'value' => 'signup', 'label' => 'Signed up']`.
     *
     * @param  array<string, mixed>  $data  The label is optional and defaults like the other constructors.
     *
     * @throws \ValueError When the type is not a FunnelStepType.
     * @throws \InvalidArgumentException When the value is missing or empty.
     */
    public static function fromArray(array $data): self
    {
        $type = FunnelStepType::from((string) ($data['type'] ?? ''));
        $value = (string) ($data['value'] ?? '');
        $label = isset($data['label']) ? (string) $data['label'] : null;

        return new self($type, $value, $label ?? ($type->is(FunnelStepType::PathPrefix) ? $value.'*' : $value));
    }

    /**
     * A page view of exactly this path.
     */
    public static function path(string $path, ?string $label = null): self
    {
        return new self(FunnelStepType::Path, $path, $label ?? $path);
    }

    /**
     * A page view of any path that starts with this prefix.
     */
    public static function pathStartingWith(string $prefix, ?string $label = null): self
    {
        return new self(FunnelStepType::PathPrefix, $prefix, $label ?? $prefix.'*');
    }

    /**
     * A custom event with this name.
     */
    public static function event(string $name, ?string $label = null): self
    {
        return new self(FunnelStepType::Event, $name, $label ?? $name);
    }

    /**
     * A goal with this name.
     */
    public static function goal(string $name, ?string $label = null): self
    {
        return new self(FunnelStepType::Goal, $name, $label ?? $name);
    }

    /**
     * Whether a raw event satisfies this step. Matching is exact and case-sensitive on every engine.
     */
    public function matches(string $type, ?string $path, ?string $name): bool
    {
        if ($type !== $this->type->eventType()->value) {
            return false;
        }

        return match ($this->type) {
            FunnelStepType::Path => $path === $this->value,
            FunnelStepType::PathPrefix => $path !== null && str_starts_with($path, $this->value),
            FunnelStepType::Event, FunnelStepType::Goal => $name === $this->value,
        };
    }
}
