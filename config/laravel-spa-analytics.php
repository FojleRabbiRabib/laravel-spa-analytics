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

];
