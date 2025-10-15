<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout', 'broadcasting/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    
    // SECURITY: In production, replace '*' with your actual frontend domain(s)
    // Example: ['https://yourdomain.com', 'https://www.yourdomain.com']
    'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', '*')),
    
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept', 'Origin'],
    'exposed_headers' => [],
    'max_age' => 3600,
    'supports_credentials' => true,
];
