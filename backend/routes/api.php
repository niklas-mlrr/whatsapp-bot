<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\WhatsAppWebhookController;
use App\Http\Controllers\Api\WhatsAppMessageController;
use App\Http\Controllers\Api\MessageStatusController;
use App\Http\Controllers\WebSocketController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\PresenceController;
use App\Events\MessageSent;
use App\Models\User;
use App\Models\WhatsAppMessage;

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

// Public routes
Route::post('/login', [\App\Http\Controllers\Api\AuthController::class, 'login']);
Route::post('/test', function () {
    return response()->json(['status' => 'ok']);
});

// Test endpoint to check database connection
Route::get('/test-db', function () {
    try {
        $userCount = \App\Models\User::count();
        $chatCount = \App\Models\Chat::count();
        return response()->json([
            'status' => 'ok',
            'users' => $userCount,
            'chats' => $chatCount,
            'database' => 'connected'
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
            'database' => 'error'
        ], 500);
    }
});

// Simple test endpoint for chats
Route::get('/test-chats', function () {
    try {
        $chats = \DB::table('chats')->select(['id', 'name', 'type', 'updated_at'])->get();
        return response()->json([
            'status' => 'ok',
            'data' => $chats
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage()
        ], 500);
    }
});

// Test endpoint that mimics the ChatController logic
Route::get('/test-chats-auth', function () {
    try {
        // Simulate the exact query from ChatController
        $chats = \DB::select("
            SELECT c.id, c.name, c.type, c.updated_at
            FROM chats c
            INNER JOIN chat_user cu ON c.id = cu.chat_id
            WHERE cu.user_id = ?
            ORDER BY c.updated_at DESC
        ", [1]); // Use user ID 1 (John Doe)
        
        $formattedChats = array_map(function ($chat) {
            return [
                'id' => $chat->id,
                'name' => $chat->name,
                'is_group' => $chat->type === 'group',
                'avatar_url' => null,
                'updated_at' => $chat->updated_at,
                'unread_count' => 0,
                'users' => [],
                'last_message' => null
            ];
        }, $chats);

        return response()->json([
            'data' => $formattedChats,
            'total' => count($formattedChats),
            'per_page' => count($formattedChats),
            'current_page' => 1,
            'last_page' => 1
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 500);
    }
});

// Test endpoint to check user chats relationship
Route::get('/test-user-chats', function () {
    try {
        $user = \App\Models\User::first();
        if (!$user) {
            return response()->json(['error' => 'No users found'], 404);
        }
        
        $chats = $user->chats()->get();
        $chatCount = $chats->count();
        
        return response()->json([
            'status' => 'ok',
            'user_id' => $user->id,
            'user_name' => $user->name,
            'chat_count' => $chatCount,
            'chats' => $chats->toArray()
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 500);
    }
});

// Test endpoint to check database tables directly
Route::get('/test-tables', function () {
    try {
        $users = \DB::table('users')->get();
        $chats = \DB::table('chats')->get();
        $chatUser = \DB::table('chat_user')->get();
        
        return response()->json([
            'status' => 'ok',
            'users_count' => $users->count(),
            'chats_count' => $chats->count(),
            'chat_user_count' => $chatUser->count(),
            'users' => $users->take(3)->toArray(),
            'chats' => $chats->take(3)->toArray(),
            'chat_user' => $chatUser->take(3)->toArray()
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 500);
    }
});

// Webhook endpoint (public)
Route::post('/whatsapp/webhook', [WhatsAppWebhookController::class, 'handle'])->name('whatsapp.webhook');

// Backward compatibility for old webhook URL
Route::post('/whatsapp-webhook', [WhatsAppWebhookController::class, 'handle']);

// Simple auth check endpoint
Route::get('/check-auth', function () {
    return response()->json(['authenticated' => auth()->check()]);
});

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
    Route::get('/chats-simple', function () {
        try {
            $user = auth()->user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }
            
            $chats = \DB::select("
                SELECT c.id, c.name, c.type, c.updated_at
                FROM chats c
                INNER JOIN chat_user cu ON c.id = cu.chat_id
                WHERE cu.user_id = ?
                ORDER BY c.updated_at DESC
            ", [$user->id]);
            
            $formattedChats = [];
            foreach ($chats as $chat) {
                $formattedChats[] = [
                    'id' => $chat->id,
                    'name' => $chat->name,
                    'is_group' => $chat->type === 'group',
                    'avatar_url' => null,
                    'updated_at' => $chat->updated_at,
                    'unread_count' => 0,
                    'users' => [],
                    'last_message' => null
                ];
            }

            return response()->json([
                'data' => $formattedChats,
                'total' => count($formattedChats),
                'per_page' => count($formattedChats),
                'current_page' => 1,
                'last_page' => 1
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch chats',
                'message' => $e->getMessage()
            ], 500);
        }
    });
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
    });
    
    // User presence
    Route::post('/presence/online', [PresenceController::class, 'setOnline']);
    Route::post('/presence/away', [PresenceController::class, 'setAway']);
    Route::post('/presence/typing/{chat}', [PresenceController::class, 'setTyping']);
});
