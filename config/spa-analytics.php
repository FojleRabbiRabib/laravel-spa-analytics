<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch for tracking. When false, the package's middleware and
    | facade calls become no-ops — useful for local/testing environments.
    |
    */

    'enabled' => env('SPA_ANALYTICS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Number of days raw events are kept before pruning. Null means "keep
    | everything" (the default) — rollup tables supplement raw data, they
    | never replace or delete it.
    |
    */

    'retention_days' => env('SPA_ANALYTICS_RETENTION_DAYS'),

    /*
    |--------------------------------------------------------------------------
    | Identity
    |--------------------------------------------------------------------------
    |
    | Visitor identity: first-party cookie plus the client-side device
    | fingerprint collector. tls_fingerprint_header is the request header your
    | proxy or CDN forwards the JA4 hash in; null disables the TLS signal.
    | relink adopts a previous visitor id when a first-time fingerprint matches
    | exactly one known visitor. It is off by default because devices with
    | identical signals (two of the same phone model) can be merged.
    |
    */

    'identity' => [
        'register_middleware' => true,
        'cookie_name' => 'spa_analytics_vid',
        'cookie_lifetime_days' => 365,
        'tls_fingerprint_header' => null,
        'nonce_ttl_seconds' => 60,
        'route_prefix' => 'spa-analytics',
        'rate_limit_per_minute' => 30,
        'relink' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracking
    |--------------------------------------------------------------------------
    |
    | Page view capture. write_mode is defer (after the response is sent),
    | queue (a queued job, using connection and queue below) or sync. Paths in
    | excluded_paths use request()->is() patterns. Host and user agent lists
    | are case-insensitive substring matches used to classify referrers and
    | flag bots; bots are stored with is_bot = true, never dropped.
    |
    */

    'tracking' => [
        'register_middleware' => true,
        'write_mode' => 'defer',
        'connection' => null,
        'queue' => null,
        'excluded_paths' => ['up', 'spa-analytics/*', 'reset-password/*', 'password/reset/*'],
        'bot_patterns' => ['bot', 'crawl', 'spider', 'slurp', 'headless', 'curl', 'wget', 'python-requests', 'lighthouse', 'preview'],
        'search_hosts' => ['google.', 'bing.', 'duckduckgo.', 'yahoo.', 'baidu.', 'yandex.', 'ecosia.', 'brave.'],
        'social_hosts' => ['facebook.', 'fb.', 't.co', 'twitter.', 'x.com', 'linkedin.', 'instagram.', 'reddit.', 'youtube.', 'pinterest.', 'tiktok.', 'lnkd.in'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sessions
    |--------------------------------------------------------------------------
    |
    | A session continues while the visitor's next page view arrives within
    | timeout_minutes of their previous one; otherwise a new session starts.
    | Session writes use cache locks: in production use a cache store shared by
    | all app servers (redis, database, memcached). The array store only locks
    | within one process, so it is for tests.
    |
    */

    'sessions' => [
        'timeout_minutes' => 30,
    ],

];
