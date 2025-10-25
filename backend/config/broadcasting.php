<?php

$defaultAppUrl = parse_url(env('APP_URL', 'http://localhost'));
$defaultHost = $defaultAppUrl['host'] ?? '127.0.0.1';
$defaultScheme = $defaultAppUrl['scheme'] ?? 'http';
$defaultPort = $defaultAppUrl['port'] ?? ($defaultScheme === 'https' ? 443 : 80);

return [
    'default' => env('BROADCAST_DRIVER', 'reverb'),

    'connections' => [
        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY', 'whatsapp-bot-key'),
            'secret' => env('REVERB_APP_SECRET', 'whatsapp-bot-secret'),
            'app_id' => env('REVERB_APP_ID', 'whatsapp-bot'),
            'options' => [
                'host' => env('REVERB_HOST', $defaultHost),
                'port' => (int) env('REVERB_PORT', $defaultPort),
                'scheme' => env('REVERB_SCHEME', $defaultScheme),
                'useTLS' => (env('REVERB_SCHEME', $defaultScheme) === 'https'),
            ],
        ],
        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY', 'whatsapp-bot-key'),
            'secret' => env('PUSHER_APP_SECRET', 'whatsapp-bot-secret'),
            'app_id' => env('PUSHER_APP_ID', 'whatsapp-bot'),
            'options' => [
                'host' => env('PUSHER_HOST', $defaultHost),
                'port' => env('PUSHER_PORT', $defaultPort),
                'scheme' => env('PUSHER_SCHEME', $defaultScheme),
                'encrypted' => false,
                'useTLS' => env('PUSHER_SCHEME', $defaultScheme) === 'https',
                'cluster' => env('PUSHER_APP_CLUSTER', 'mt1'),
            ],
        ],
        'log' => [
            'driver' => 'log',
        ],
        'null' => [
            'driver' => 'null',
        ],
    ],
];
