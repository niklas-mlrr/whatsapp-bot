<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;

Route::middleware('web')->group(function () {
    // Main route
    Route::get('/', function () {
        return view('welcome');
    });

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

    // Temporary route to check encryption configuration
    Route::get('/check-encryption', function() {
        return [
            'has_key' => !empty(config('app.key')),
            'key_length' => strlen(config('app.key')),
            'cipher' => config('app.cipher'),
        ];
    });

    // Route to test encrypter service
    Route::get('/test-encrypter', function() {
        try {
            if (!class_exists('Illuminate\\Encryption\\Encrypter')) {
                throw new \Exception('Encrypter class not found');
            }
            
            if (empty(config('app.key'))) {
                throw new \Exception('APP_KEY not set in config');
            }
            
            $encrypter = app('encrypter');
            return response()->json([
                'status' => 'success',
                'message' => 'Encrypter service is available',
                'config' => [
                    'key' => config('app.key'),
                    'cipher' => config('app.cipher')
                ],
                'test_encryption' => $encrypter->encrypt('test')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
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
        'db-test.php' // Include our test routes
    ];

    foreach ($testRoutes as $routeFile) {
        $path = __DIR__ . '/' . $routeFile;
        if (file_exists($path)) {
            require $path;
        }
    }

    // Catch-all route for SPA (if using Vue Router in history mode)
    Route::get('/{any}', function () {
        return view('welcome');
    })->where('any', '.*');
});