<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Architecture §1.1: "CORS locked to the SPA's own origin per
    | environment (staging and production are different origins, not a
    | shared allowlist)." allowed_origins is driven entirely by
    | FRONTEND_URL - never '*' - so each environment's .env pins its own
    | SPA origin instead of sharing one allowlist across environments.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(explode(',', env('FRONTEND_URL', ''))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Correlation-Id'],

    'max_age' => 0,

    'supports_credentials' => false,

];
