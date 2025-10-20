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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
Route::post('/login', [\App\Http\Controllers\Api\AuthController::class, 'login'])
    ->middleware('throttle:5,1'); // Rate limit: 5 attempts per minute

Route::post('/test', function () {
    return response()->json(['status' => 'ok']);
});

// ============================================================================
// DEBUG/TEST ENDPOINTS - ONLY AVAILABLE IN DEVELOPMENT
// These endpoints are DISABLED in production for security
// ============================================================================
if (config('app.env') !== 'production') {
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
        // Simulate the exact query from ChatController using single app user
        $user = \App\Models\User::getFirstUser();
        $userId = $user?->id ?? 1;

        $chats = \DB::select("
            SELECT c.id, c.name, c.type, c.updated_at
            FROM chats c
            INNER JOIN chat_user cu ON c.id = cu.chat_id
            WHERE cu.user_id = ?
            ORDER BY c.updated_at DESC
        " , [$userId]);

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
        $user = \App\Models\User::getFirstUser();
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
} // End of debug/test endpoints

// ============================================================================
// PRODUCTION ENDPOINTS - Always available
// ============================================================================

// Webhook endpoint (temporarily without middleware for testing)
// TODO: Re-enable middleware after testing
Route::post('/whatsapp/webhook', [WhatsAppWebhookController::class, 'handle'])->name('whatsapp.webhook');
Route::post('/whatsapp-webhook', [WhatsAppWebhookController::class, 'handle']);

// Internal endpoint for receiver to fetch messages (uses webhook secret auth)
Route::middleware(['verify.webhook'])->group(function () {
    Route::get('/internal/messages/{id}', [WhatsAppMessageController::class, 'show']);
});

// Simple auth check endpoint
Route::get('/check-auth', function () {
    // Single-user setup: always report authenticated (token-based checks still apply on protected routes)
    return response()->json(['authenticated' => true]);
});

// Protected routes (require authentication + rate limiting)
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
    
    // Update message status by WhatsApp message ID (called by receiver)
    Route::post('/messages/update-status', [MessageStatusController::class, 'updateStatusByWhatsAppId']);

    // Message status
    Route::prefix('messages/{message}')->group(function () {
        Route::post('read', [MessageStatusController::class, 'markAsRead']);
        Route::post('react', [MessageStatusController::class, 'react']);
    });

    // Chat management
    Route::get('/chats', [ChatController::class, 'index']);
    Route::get('/chats-simple', function () {
        try {
            // Use the single app user instead of auth()->user()
            $user = \App\Models\User::getFirstUser();

            $chats = \DB::select("
                SELECT c.id, c.name, c.type, c.updated_at
                FROM chats c
                INNER JOIN chat_user cu ON c.id = cu.chat_id
                WHERE cu.user_id = ?
                ORDER BY c.updated_at DESC
            " , [$user->id]);

            $formattedChats = [];
            foreach ($chats as $chat) {
                // Create a Chat model instance to access computed attributes
                $chatModel = new \App\Models\Chat();
                $chatModel->forceFill([
                    'id' => $chat->id,
                    'name' => $chat->name,
                    'type' => $chat->type,
                ]);

                $formattedChats[] = [
                    'id' => $chat->id,
                    'name' => $chat->name,
                    'is_group' => $chat->type === 'group',
                    'avatar_url' => $chatModel->avatar_url,
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
    Route::post('/chats', [ChatController::class, 'store']);
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

        // Last read message tracking
        Route::post('/{chat}/last-read', [ChatController::class, 'saveLastRead']);
        Route::get('/{chat}/last-read', [ChatController::class, 'getLastRead']);

        // Mute/unmute chat
        Route::post('/{chat}/mute', [ChatController::class, 'toggleMute']);

        // Mark chat as read
        Route::post('/{chat}/read', [ChatController::class, 'markAsRead']);

        // Approve pending chat
        Route::post('/{chat}/approve', [ChatController::class, 'approve']);

        // Reject pending chat
        Route::post('/{chat}/reject', [ChatController::class, 'reject']);

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

// Debug endpoint for chats
Route::get('/debug-chats', function () {
    try {
        // Use the single app user instead of auth()->user()
        $user = \App\Models\User::getFirstUser();
        if (!$user) {
            return response()->json(['error' => 'No users found'], 404);
        }

        $rawChats = DB::select("
            SELECT
                c.*,
                cu.user_id
            FROM chats c
            LEFT JOIN chat_user cu ON c.id = cu.chat_id
            WHERE cu.user_id = ?
        " , [$user->id]);

        $tableInfo = [
            'chats_exist' => Schema::hasTable('chats'),
            'chat_user_exists' => Schema::hasTable('chat_user'),
            'total_chats' => DB::table('chats')->count(),
            'total_chat_users' => DB::table('chat_user')->count(),
            'user_id' => $user->id,
            'raw_chats' => $rawChats
        ];

        return response()->json($tableInfo);
    } catch (\Exception $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 500);
    }
});

// Test endpoint to approve a chat
Route::get('/test-approve/{chatId}', function ($chatId) {
    try {
        $chat = \App\Models\Chat::findOrFail($chatId);
        
        \Log::info('Before update', [
            'chat_id' => $chat->id,
            'pending_approval' => $chat->pending_approval,
            'fillable' => $chat->getFillable()
        ]);
        
        $chat->update(['pending_approval' => false]);
        
        $chat->refresh();
        
        \Log::info('After update', [
            'chat_id' => $chat->id,
            'pending_approval' => $chat->pending_approval
        ]);
        
        return response()->json([
            'status' => 'success',
            'chat_id' => $chat->id,
            'pending_approval' => $chat->pending_approval,
            'fillable' => $chat->getFillable()
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 500);
    }
});

// Test endpoint to simulate incoming message from new number
Route::get('/test-pending-chat', function () {
    try {
        // Use a random number to ensure it's truly new
        $randomNumber = '49' . rand(1000000000, 9999999999);
        $testPhone = $randomNumber . '@s.whatsapp.net';
        
        // Simulate what happens when a message arrives
        $messageService = app(\App\Services\WhatsAppMessageService::class);
        
        // Create a test message data
        $messageData = new \App\DataTransferObjects\WhatsAppMessageData(
            sender: $testPhone,
            chat: $testPhone,
            type: 'text',
            content: 'Test message from new number',
            sending_time: now()->toDateTimeString(),
            messageId: 'test_' . time()
        );
        
        $messageService->handle($messageData);
        
        // Check if chat was created with pending_approval
        $chat = \App\Models\Chat::where('is_group', false)
            ->get()
            ->first(function($c) use ($testPhone) {
                $metadata = is_string($c->metadata) ? json_decode($c->metadata, true) : $c->metadata;
                if (!$metadata || !isset($metadata['whatsapp_id'])) {
                    return false;
                }
                return $metadata['whatsapp_id'] === $testPhone;
            });
        
        return response()->json([
            'status' => 'success',
            'message' => 'Test message processed from ' . $testPhone,
            'chat_created' => $chat ? true : false,
            'pending_approval' => $chat ? (bool)$chat->pending_approval : null,
            'chat_id' => $chat ? $chat->id : null,
            'chat_name' => $chat ? $chat->name : null,
            'test_phone' => $testPhone
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 500);
    }
});