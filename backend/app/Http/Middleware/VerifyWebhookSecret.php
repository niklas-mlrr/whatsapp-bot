<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class VerifyWebhookSecret
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $webhookSecret = config('app.webhook_secret');
        
        // If no webhook secret is configured, log a warning but allow the request
        // This is for backward compatibility during migration
        if (empty($webhookSecret)) {
            Log::warning('SECURITY WARNING: No webhook secret configured. Set WEBHOOK_SECRET in .env');
            return $next($request);
        }
        
        // Check for the secret in multiple possible locations
        $providedSecret = $request->header('X-Webhook-Secret') 
                       ?? $request->header('X-API-Key')
                       ?? $request->input('webhook_secret');
        
        // Verify the secret
        if (!hash_equals($webhookSecret, $providedSecret ?? '')) {
            Log::warning('Webhook authentication failed', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'url' => $request->fullUrl(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized: Invalid webhook secret',
            ], 401);
        }
        
        return $next($request);
    }
}
