<?php

return [
    'default' => env('BROADCAST_DRIVER', 'pusher'),

    'connections' => [
        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY', 'whatsapp-bot-key'),
            'secret' => env('REVERB_APP_SECRET', 'whatsapp-bot-secret'),
            'app_id' => env('REVERB_APP_ID', 'whatsapp-bot'),
            'options' => [
                'host' => env('REVERB_HOST', '127.0.0.1'),
                'port' => env('REVERB_PORT', 8080),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME') === 'https',
            ],
        ],
        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY', 'whatsapp-bot-key'),
            'secret' => env('PUSHER_APP_SECRET', 'whatsapp-bot-secret'),
            'app_id' => env('PUSHER_APP_ID', 'whatsapp-bot'),
            'options' => [
                'host' => env('PUSHER_HOST', '127.0.0.1'),
                'port' => env('PUSHER_PORT', 6001),
                'scheme' => env('PUSHER_SCHEME', 'http'),
                'encrypted' => false,
                'useTLS' => env('PUSHER_SCHEME') === 'https',
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
