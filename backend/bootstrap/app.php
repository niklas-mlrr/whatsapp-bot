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
use Illuminate\Http\Request;
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
        // Trust the local nginx reverse proxy so X-Forwarded-Proto (https) is
        // honored. Without this, Laravel sees the proxied request as http and
        // generates http:// URLs/redirects behind our TLS-terminating nginx.
        // PHP runs on 127.0.0.1:8000 (nginx-only), so trusting loopback is safe.
        $middleware->trustProxies(at: ['127.0.0.1', '::1'], headers:
            Request::HEADER_X_FORWARDED_FOR |
            Request::HEADER_X_FORWARDED_HOST |
            Request::HEADER_X_FORWARDED_PORT |
            Request::HEADER_X_FORWARDED_PROTO);

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
        $middleware->web(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        $middleware->web(\Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();
