<?php

declare(strict_types=1);

namespace FojleRabbiRabib\LaravelSpaAnalytics\Enums;

enum RollupDimension: string
{
    case Total = 'total';
    case Path = 'path';
    case ExitPath = 'exit_path';
    case ReferrerType = 'referrer_type';
    case ReferrerHost = 'referrer_host';
    case UtmCampaign = 'utm_campaign';
    case DeviceType = 'device_type';
    case Os = 'os';
    case Browser = 'browser';
    case Country = 'country';
    case VisitorType = 'visitor_type';
    case Event = 'event';
    case Goal = 'goal';

    /**
     * The analytics_sessions column behind each dimension that describes a session, keyed by column.
     */
    public const SESSION_COLUMNS = [
        'referrer_type' => self::ReferrerType,
        'referrer_host' => self::ReferrerHost,
        'utm_campaign' => self::UtmCampaign,
        'device_type' => self::DeviceType,
        'os' => self::Os,
        'browser' => self::Browser,
        'country' => self::Country,
    ];

    /**
     * The analytics_sessions column behind this dimension, when it describes a session.
     */
    public function sessionColumn(): ?string
    {
        $column = array_search($this, self::SESSION_COLUMNS, true);

        return $column === false ? null : $column;
    }

    /**
     * The stored value for a grouped value of this dimension; the new-versus-returning flag arrives as a boolean or integer.
     */
    public function storedValue(mixed $value): string
    {
        if ($this->is(self::VisitorType)) {
            return in_array($value, [true, 1, '1', 't', 'true'], true) ? 'new' : 'returning';
        }

        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }

    /**
     * Whether this case equals the given case.
     */
    public function is(self $dimension): bool
    {
        return $this === $dimension;
    }

    /**
     * Human-readable name of the case.
     */
    public function label(): string
    {
        return match ($this) {
            self::Total => 'Total',
            self::Path => 'Path',
            self::ExitPath => 'Exit path',
            self::ReferrerType => 'Referrer type',
            self::ReferrerHost => 'Referrer host',
            self::UtmCampaign => 'UTM campaign',
            self::DeviceType => 'Device type',
            self::Os => 'OS',
            self::Browser => 'Browser',
            self::Country => 'Country',
            self::VisitorType => 'Visitor type',
            self::Event => 'Event',
            self::Goal => 'Goal',
        };
    }

    /**
     * Display color token for the case.
     */
    public function color(): string
    {
        return match ($this) {
            self::Total => 'gray',
            self::Path, self::ExitPath => 'blue',
            self::ReferrerType, self::ReferrerHost, self::UtmCampaign => 'purple',
            self::DeviceType, self::Os, self::Browser, self::Country, self::VisitorType => 'green',
            self::Event, self::Goal => 'orange',
        };
    }

    /**
     * A string that identifies one row of this dimension, for collecting rows before they are written.
     */
    public function key(string|\BackedEnum $value): string
    {
        return $this->value."\0".($value instanceof \BackedEnum ? $value->value : $value);
    }

    /**
     * The dimension and value of a key made by key().
     *
     * @return array{self, string}
     */
    public static function parseKey(string $key): array
    {
        [$dimension, $value] = explode("\0", $key, 2);

        return [self::from($dimension), $value];
    }

    /**
     * Backing values of every case.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Value and label pairs for every case.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $dimension): array => ['value' => $dimension->value, 'label' => $dimension->label()],
            self::cases(),
        );
    }

    /**
     * The case used when none is specified.
     */
    public static function default(): self
    {
        return self::Total;
    }
}
