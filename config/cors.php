<?php

// Build list of allowed origins from env + common dev origins
$frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/');
$allowedOrigins = array_unique(array_filter([
    $frontendUrl,
    'http://localhost:5173',
    'http://localhost:3000',
    'http://127.0.0.1:5173',
]));

return [
    'paths'                    => ['api/*', 'storage/*', 'sanctum/csrf-cookie', 'broadcasting/auth'],
    'allowed_methods'          => ['*'],
    'allowed_origins'          => $allowedOrigins,
    'allowed_origins_patterns' => [],
    'allowed_headers'          => ['*'],
    'exposed_headers'          => ['Content-Disposition'],
    'max_age'                  => 0,
    'supports_credentials'     => true,
];
