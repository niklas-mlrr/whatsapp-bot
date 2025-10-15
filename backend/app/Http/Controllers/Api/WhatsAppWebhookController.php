<?php

namespace App\Http\Controllers\Api;

use App\DataTransferObjects\WhatsAppMessageData;
use App\Http\Controllers\Controller;
use App\Http\Requests\WhatsAppMessageRequest;
use App\Services\WhatsAppMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function __construct(private readonly WhatsAppMessageService $messageService)
    {
    }

    public function handle(WhatsAppMessageRequest $request): JsonResponse
    {
        // Verify webhook secret
        $webhookSecret = config('app.webhook_secret');
        
        if (!empty($webhookSecret)) {
            $providedSecret = $request->header('X-Webhook-Secret') 
                           ?? $request->header('X-API-Key')
                           ?? $request->input('webhook_secret');
            
            if (!hash_equals($webhookSecret, $providedSecret ?? '')) {
                Log::warning('Webhook authentication failed', [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
                
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized: Invalid webhook secret',
                ], 401);
            }
        } else {
            Log::warning('SECURITY WARNING: No webhook secret configured. Set WEBHOOK_SECRET in .env');
        }
        
        try {
            $messageData = WhatsAppMessageData::fromRequest($request);
            $this->messageService->handle($messageData);

            return response()->json([
                'status' => 'ok',
                'message' => 'Daten erfolgreich empfangen',
            ]);
        } catch (\Exception $e) {
            Log::channel('whatsapp')->critical('Ein unerwarteter Fehler im Webhook ist aufgetreten.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Ein interner Serverfehler ist aufgetreten.',
            ], 500);
        }
    }
}
