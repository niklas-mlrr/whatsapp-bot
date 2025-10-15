<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\WhatsAppWebhookController;
use App\Http\Controllers\Api\WhatsAppMessageController;
use App\Http\Controllers\Api\MessageStatusController;
use App\Http\Controllers\WebSocketController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\PresenceController;

/*
|--------------------------------------------------------------------------
| Production API Routes
|--------------------------------------------------------------------------
|
| These are the production-ready API routes with proper security measures.
| All debug/test endpoints have been removed for production deployment.
|
*/

// Public routes
Route::post('/login', [\App\Http\Controllers\Api\AuthController::class, 'login'])
    ->middleware('throttle:5,1'); // Rate limit: 5 attempts per minute

// Simple auth check endpoint
Route::get('/check-auth', function () {
    // Single-user setup: always report authenticated (token-based checks still apply on protected routes)
    return response()->json(['authenticated' => true]);
});

// Webhook endpoint (secured with webhook secret)
Route::middleware(['verify.webhook', 'throttle:60,1'])->group(function () {
    Route::post('/whatsapp/webhook', [WhatsAppWebhookController::class, 'handle'])->name('whatsapp.webhook');
    // Backward compatibility for old webhook URL
    Route::post('/whatsapp-webhook', [WhatsAppWebhookController::class, 'handle']);
});

// Protected routes (require authentication)
Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {
    // Auth routes
    Route::post('/logout', [\App\Http\Controllers\Api\AuthController::class, 'logout']);
    Route::get('/me', [\App\Http\Controllers\Api\AuthController::class, 'user']);

    // WebSocket
    Route::post('/broadcasting/webhook', [WebSocketController::class, 'webhook']);

    // Messages
    Route::apiResource('messages', WhatsAppMessageController::class)
        ->only(['index', 'show', 'destroy', 'store']);

    // Mark multiple messages as read
    Route::post('/messages/read', [MessageStatusController::class, 'markMultipleAsRead']);

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

        // Get latest messages for a chat
        Route::get('/{chat}/messages/latest', [ChatController::class, 'latestMessages']);

        // Delete a chat
        Route::delete('/{chat}', [ChatController::class, 'destroy']);
    });

    // User presence
    Route::post('/presence/online', [PresenceController::class, 'setOnline']);
    Route::post('/presence/away', [PresenceController::class, 'setAway']);
    Route::post('/presence/typing/{chat}', [PresenceController::class, 'setTyping']);
});
