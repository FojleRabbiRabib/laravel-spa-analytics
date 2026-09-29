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
    ],

];
