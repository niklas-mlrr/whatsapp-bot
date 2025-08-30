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

