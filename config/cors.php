<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(array_unique(array_merge(
        array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', ''))),
        [env('FRONTEND_URL'), 'http://127.0.0.1:8002']
    )))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['X-Cart-Token'],
    'max_age' => 0,
    'supports_credentials' => true,
];
