<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\WhatsAppMessageController;
use App\Http\Controllers\Api\MessageStatusController;
use App\Http\Controllers\WebSocketController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\PresenceController;
use App\Events\MessageSent;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Http\Requests\WhatsAppMessageRequest;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('api')->group(function () {
    // Public routes
    Route::post('/login', [\App\Http\Controllers\Api\AuthController::class, 'login']);
    Route::post('/test', function () {
        return response()->json(['status' => 'ok']);
    });

    // Simple auth check endpoint
    Route::get('/check-auth', function () {
        return response()->json(['authenticated' => auth()->check()]);
    });

    // WhatsApp Webhook
    Route::post('/api/whatsapp-webhook', [\App\Http\Controllers\Api\WhatsAppWebhookController::class, 'handle'])
        ->middleware('api')
        ->name('api.whatsapp.webhook');

    // Protected routes
    Route::middleware(['auth:sanctum'])->group(function () {
        // Auth routes
        Route::post('/logout', [\App\Http\Controllers\Api\AuthController::class, 'logout']);
        Route::get('/me', [\App\Http\Controllers\Api\AuthController::class, 'user']);

        // WebSocket
        Route::post('/broadcasting/auth', [WebSocketController::class, 'authenticate']);
        Route::post('/broadcasting/webhook', [WebSocketController::class, 'webhook']);

        // Messages
        Route::apiResource('messages', WhatsAppMessageController::class)
            ->only(['index', 'show', 'destroy', 'store']);

        // Message status
        Route::prefix('messages/{message}')->group(function () {
            Route::post('read', [MessageStatusController::class, 'markAsRead']);
            Route::post('react', [MessageStatusController::class, 'react']);
        });

        // Chat management
        Route::get('/chats', [ChatController::class, 'index']);
        Route::post('/upload', [WhatsAppMessageController::class, 'upload']);
        
        // Message reactions
        Route::post('/messages/{message}/reactions', [MessageStatusController::class, 'addReaction'])
            ->name('messages.reactions.add');
            
        Route::delete('/messages/{message}/reactions/{userId}', [MessageStatusController::class, 'removeReaction']);
        
        // Chat management routes
        Route::prefix('chats')->group(function () {
            // Create a new direct chat
            Route::post('/direct', [ChatController::class, 'createDirectChat']);
            
            // Create a new group chat
            Route::post('/group', [ChatController::class, 'createGroupChat']);
            
            // Update chat details
            Route::put('/{chat}', [ChatController::class, 'update']);
            
            // Add participants to a group chat
            Route::post('/{chat}/participants', [ChatController::class, 'addParticipants']);
            
            // Remove participants from a group chat
            Route::delete('/{chat}/participants', [ChatController::class, 'removeParticipants']);
            
            // Leave a chat
            Route::post('/{chat}/leave', [ChatController::class, 'leaveChat']);
            
            // Mute/unmute chat
            Route::post('/{chat}/mute', [ChatController::class, 'toggleMute']);
            
            // Mark chat as read
            Route::post('/{chat}/read', [ChatController::class, 'markAsRead']);
            
            // Get chat messages
            Route::get('/{chat}/messages', [ChatController::class, 'messages']);
        });
        
        // User presence
        Route::post('/presence/online', [PresenceController::class, 'setOnline']);
        Route::post('/presence/away', [PresenceController::class, 'setAway']);
        Route::post('/presence/typing/{chat}', [PresenceController::class, 'setTyping']);
    });
});
