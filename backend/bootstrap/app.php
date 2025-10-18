<?php

// Load custom PHP configuration
$customPhpIni = __DIR__ . '/../config/custom-php.ini';
if (file_exists($customPhpIni)) {
    $config = parse_ini_file($customPhpIni);
    foreach ($config as $key => $value) {
        if (strpos($key, 'memory_limit') !== false) {
            ini_set('memory_limit', $value);
        }
    }
}

// Set memory limit early in the bootstrap process
$memoryLimit = env('MEMORY_LIMIT', '1024M');
ini_set('memory_limit', $memoryLimit);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Reverb\ReverbServiceProvider;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withProviders([
        ReverbServiceProvider::class,
        \App\Providers\AppServiceProvider::class,
        \App\Providers\RouteServiceProvider::class,
        \App\Providers\BroadcastServiceProvider::class,
    ])

    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
            'auth.sanctum' => \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            'verify.webhook' => \App\Http\Middleware\VerifyWebhookSecret::class,
            'verify.receiver' => \App\Http\Middleware\VerifyReceiverApiKey::class,
        ]);
        
        // Add CORS middleware to API routes
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);
        
        // Completely disable CSRF middleware
        $middleware->remove(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);
        
        // Allow WebSocket connections from the same origin
        $middleware->web(\Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();
