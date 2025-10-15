<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class VerifyReceiverApiKey
{
    /**
     * Handle an incoming request for receiver service endpoints.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $receiverApiKey = config('app.receiver_api_key');
        
        // If no API key is configured, log a critical warning
        if (empty($receiverApiKey)) {
            Log::critical('SECURITY CRITICAL: No receiver API key configured. Set RECEIVER_API_KEY in .env');
            
            // In production, this should block the request
            if (config('app.env') === 'production') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Service unavailable: API key not configured',
                ], 503);
            }
            
            // In development, allow but warn
            return $next($request);
        }
        
        // Check for the API key in headers
        $providedKey = $request->header('X-API-Key') 
                    ?? $request->header('Authorization');
        
        // Remove 'Bearer ' prefix if present
        if ($providedKey && str_starts_with($providedKey, 'Bearer ')) {
            $providedKey = substr($providedKey, 7);
        }
        
        // Verify the API key using constant-time comparison
        if (!hash_equals($receiverApiKey, $providedKey ?? '')) {
            Log::warning('Receiver API authentication failed', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'url' => $request->fullUrl(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized: Invalid API key',
            ], 401);
        }
        
        return $next($request);
    }
}
