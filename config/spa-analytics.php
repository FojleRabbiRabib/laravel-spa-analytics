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
    | rate_limit_per_minute limits each visitor cookie on the handshake and
    | identify endpoints; rate_limit_per_ip_per_minute is a higher ceiling per
    | address that stops clients rotating cookies while leaving room for many
    | visitors behind one shared address (mobile carriers, offices).
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
        'rate_limit_per_ip_per_minute' => 600,
        'relink' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracking
    |--------------------------------------------------------------------------
    |
    | Page view capture. write_mode is defer (after the response is sent),
    | queue (a queued job, using connection and queue below) or sync. Paths in
    | excluded_paths use request()->is() patterns and also filter the paths
    | the browser script reports. Host and user agent lists
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
    | Audience
    |--------------------------------------------------------------------------
    |
    | Device, OS, browser and country are stored on the session from its first
    | page view. Device, OS and browser come from the user agent. country_header
    | is the request header your CDN or proxy fills with the visitor's country
    | code (CF-IPCountry on Cloudflare, CloudFront-Viewer-Country on
    | CloudFront); null leaves the country empty. Set it only when that header
    | is always overwritten at your edge, otherwise a client can forge it.
    |
    */

    'audience' => [
        'country_header' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Collect
    |--------------------------------------------------------------------------
    |
    | The browser script posts SPA page views, outbound clicks, scroll depth
    | and manual events to the collect endpoint (under identity.route_prefix).
    | rate_limit_per_minute limits each visitor cookie and
    | rate_limit_per_ip_per_minute each address, both higher than the identity
    | endpoints because a visit sends many small batches.
    |
    */

    'collect' => [
        'rate_limit_per_minute' => 120,
        'rate_limit_per_ip_per_minute' => 1200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rollups
    |--------------------------------------------------------------------------
    |
    | spa-analytics:rollup summarises raw events and sessions into hourly and
    | daily rows in analytics_rollups; spa-analytics:prune deletes raw rows
    | older than retention_days once their day has been rolled up. With
    | schedule on, the package registers the rollup hourly and the prune daily
    | in the Laravel scheduler; turn it off to schedule them yourself.
    | lookback_hours is how many recent hours each run recomputes, so late
    | queued writes and still-open sessions are picked up.
    |
    */

    'rollups' => [
        'schedule' => true,
        'lookback_hours' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Stats
    |--------------------------------------------------------------------------
    |
    | The Stats facade reads rollups, so its numbers cover completed hours.
    | Stats::realtime() reads the raw events of the last realtime_minutes
    | instead, for who is on the site right now.
    |
    */

    'stats' => [
        'realtime_minutes' => 5,
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
