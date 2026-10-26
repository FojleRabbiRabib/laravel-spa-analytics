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
    | Database
    |--------------------------------------------------------------------------
    |
    | The database connection the package tables live on, models, queries,
    | transactions and migrations included. Null uses the application's default
    | connection. Set it before the first migrate: changing it later moves no
    | data. The migrations table itself stays on the default connection.
    |
    */

    'database' => [
        'connection' => env('SPA_ANALYTICS_DB_CONNECTION'),
    ],

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
    | register_routes false skips the handshake, identify and collect routes
    | so you can register your own under the same route names (route_prefix is
    | then ignored); clear the route cache after changing it.
    |
    */

    'identity' => [
        'register_middleware' => true,
        'register_routes' => true,
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
    | Device, OS, browser, country and region are stored on the session from its first
    | page view. Device, OS and browser come from the user agent. country_header
    | is the request header your CDN or proxy fills with the visitor's country
    | code (CF-IPCountry on Cloudflare, CloudFront-Viewer-Country on
    | CloudFront); null leaves the country empty. Set it only when that header
    | is always overwritten at your edge, otherwise a client can forge it.
    | region_header is the same for the region: the header holds the part of
    | the ISO 3166-2 code after the dash (CF-Region-Code on Cloudflare, with
    | the visitor location headers turned on; CloudFront-Viewer-Country-Region
    | on CloudFront). The region is stored as the full code, such as US-CA, and
    | only when the country is known.
    | maxmind_database is the path to a local MaxMind GeoLite2 or GeoIP2 .mmdb
    | file (City for the region, Country for the country only). When set, it is
    | the only source and both headers are ignored. It needs the
    | maxmind-db/reader package; the address is read in memory and never stored.
    | Behind a proxy or CDN, configure TrustProxies so the real address is used.
    |
    */

    'audience' => [
        'country_header' => null,
        'region_header' => null,
        'maxmind_database' => null,
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
    | endpoints because a visit sends many small batches. A click on a link
    | to a file with one of download_extensions (or with the download
    | attribute) is reported as a file download instead of an outbound click.
    |
    */

    'collect' => [
        'rate_limit_per_minute' => 120,
        'rate_limit_per_ip_per_minute' => 1200,
        'download_extensions' => ['pdf', 'zip', 'gz', 'rar', '7z', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'txt', 'epub', 'mp3', 'mp4', 'dmg', 'exe', 'apk'],
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
    | disabled_dimensions lists dimensions to stop building, to keep the
    | rollup table small (for example 'utm_term', 'utm_content', 'download').
    | Stats::top() refuses a disabled dimension, and rows already stored for it
    | stay until you run spa-analytics:rollup --purge-disabled. 'total',
    | 'goal' and 'visitor_type' cannot be disabled because Stats needs them.
    |
    */

    'rollups' => [
        'schedule' => true,
        'lookback_hours' => 3,
        'disabled_dimensions' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Stats
    |--------------------------------------------------------------------------
    |
    | The Stats facade reads rollups, which cover completed hours. With live on
    | (the default) a report also adds the hours that have not been rolled up
    | yet, worked out from the raw events when you ask, so "today so far" is
    | complete within minutes; those numbers are provisional until the hourly
    | rollup runs. live_max_hours is the most hours before the running hour
    | that raw part may include; when the rollup is further behind, it is left out
    | and the report says incomplete. withLive() and withoutLive() decide per
    | report.
    | Stats::realtime() reads the raw events of the last realtime_minutes
    | instead, for who is on the site right now.
    |
    */

    'stats' => [
        'realtime_minutes' => 5,
        'live' => env('SPA_ANALYTICS_STATS_LIVE', true),
        'live_max_hours' => 24,
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
