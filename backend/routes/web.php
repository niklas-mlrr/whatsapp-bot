<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;





// WebSocket test page
Route::get('/websocket-test', function () {
    return view('websocket-test', [
        'appKey' => config('broadcasting.connections.reverb.key')
    ]);
});

// Simple WebSocket test page
Route::get('/simple-websocket-test', function () {
    return view('simple-websocket-test', [
        'appKey' => config('broadcasting.connections.reverb.key')
    ]);
});

// CSRF token route for testing
Route::get('/csrf-token', function () {
    return response()->json(['token' => csrf_token()]);
});

// Custom broadcasting auth endpoint with proper Sanctum authentication
Route::post('/broadcasting/auth', function (Illuminate\Http\Request $request) {
    // Get the authenticated user
    $user = $request->user();
    
    if (!$user) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }
    
    $socketId = $request->input('socket_id');
    $channelName = $request->input('channel_name');
    
    if (!$socketId || !$channelName) {
        return response()->json(['error' => 'Missing socket_id or channel_name'], 400);
    }
    
    // Use the Reverb configuration for authorization
    $pusher = new \Pusher\Pusher(
        config('broadcasting.connections.reverb.key'),
        config('broadcasting.connections.reverb.secret'),
        config('broadcasting.connections.reverb.app_id'),
        [
            'host' => config('broadcasting.connections.reverb.options.host'),
            'port' => config('broadcasting.connections.reverb.options.port'),
            'scheme' => config('broadcasting.connections.reverb.options.scheme'),
            'useTLS' => config('broadcasting.connections.reverb.options.useTLS'),
        ]
    );
    
    // For private channels, include user data
    if (str_starts_with($channelName, 'private-')) {
        $userData = [
            'id' => $user->id,
            'user_info' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ];
        
        $auth = $pusher->authorizeChannel($channelName, $socketId, $user->id, $userData);
    } else {
        // For public channels
        $auth = $pusher->authorizeChannel($channelName, $socketId);
    }
    
    return response($auth, 200, ['Content-Type' => 'application/json']);
})->middleware('auth:api')->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);

// Include test routes
$testRoutes = [
    'test-routes.php',
    'test-auth.php',
    'test-connection.php',
    'test-broadcast.php',
    'test-event.php',
    'test-websocket.php',
    'test-bypass.php',
    'websockets.php',
    'test-trigger.php',
    'test.php',
    'test2.php',
    'test3.php',
    'db-test.php', // Include our test routes
    'test-memory.php' // Include memory test routes
];

foreach ($testRoutes as $routeFile) {
    $path = __DIR__ . '/' . $routeFile;
    if (file_exists($path)) {
        require $path;
    }
}

