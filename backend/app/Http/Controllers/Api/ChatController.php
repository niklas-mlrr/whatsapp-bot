<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    public function __construct()
    {
        // Middleware is applied in routes/api.php
    }

    /**
     * Get all chats for the authenticated user.
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            
            // Use raw SQL query to avoid any model loading issues
            $chats = DB::select("
                SELECT 
                    c.id, 
                    c.name, 
                    c.is_group, 
                    c.updated_at,
                    c.created_at,
                    c.is_archived,
                    c.is_muted,
                    c.metadata,
                    c.type,
                    c.unread_count,
                    c.participants,
                    c.created_by
                FROM chats c
                INNER JOIN chat_user cu ON c.id = cu.chat_id
                WHERE cu.user_id = ? AND c.is_archived = false
                ORDER BY c.updated_at DESC
            ", [$user->id]);
            
            \Log::info('Chats fetched for user', [
                'user_id' => $user->id,
                'chats_count' => count($chats),
                'chats' => $chats
            ]);
            
            $formattedChats = [];
            foreach ($chats as $chat) {
                // Create a Chat model instance to access computed attributes
                $chatModel = new \App\Models\Chat();
                $chatModel->forceFill([
                    'id' => $chat->id,
                    'name' => $chat->name,
                    'is_group' => $chat->is_group,
                    'type' => $chat->type,
                    'unread_count' => $chat->unread_count,
                    'participants' => json_decode($chat->participants, true) ?? [],
                    'metadata' => json_decode($chat->metadata, true) ?? [],
                    'is_archived' => $chat->is_archived,
                    'is_muted' => $chat->is_muted,
                    'created_by' => $chat->created_by,
                    'created_at' => $chat->created_at,
                    'updated_at' => $chat->updated_at,
                ]);
                
                $formattedChats[] = [
                    'id' => $chat->id,
                    'name' => $chat->name,
                    'is_group' => $chat->is_group,
                    'avatar_url' => $chatModel->avatar_url,
                    'updated_at' => $chat->updated_at,
                    'created_at' => $chat->created_at,
                    'description' => null, // Description field doesn't exist in database
                    'is_archived' => $chat->is_archived,
                    'is_muted' => $chat->is_muted,
                    'unread_count' => $chat->unread_count,
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
            \Log::error('Error fetching chats: ' . $e->getMessage(), [
                'user_id' => $request->user()->id ?? 'unknown',
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Failed to fetch chats',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Placeholder methods for other chat operations
     */
    public function createDirectChat(Request $request)
    {
        return response()->json([
            'message' => 'Feature temporarily disabled',
        ], 501);
    }

    public function createGroupChat(Request $request)
    {
        return response()->json([
            'message' => 'Feature temporarily disabled',
        ], 501);
    }

    public function update(Request $request, $chatId)
    {
        return response()->json([
            'message' => 'Feature temporarily disabled',
        ], 501);
    }

    public function addParticipants(Request $request, $chatId)
    {
        return response()->json([
            'message' => 'Feature temporarily disabled',
        ], 501);
    }

    public function removeParticipants(Request $request, $chatId)
    {
        return response()->json([
            'message' => 'Feature temporarily disabled',
        ], 501);
    }

    public function leaveChat($chatId)
    {
        return response()->json([
            'message' => 'Feature temporarily disabled',
        ], 501);
    }

    public function toggleMute(Request $request, $chatId)
    {
        return response()->json([
            'message' => 'Feature temporarily disabled',
        ], 501);
    }

    public function markAsRead($chatId)
    {
        return response()->json([
            'message' => 'Feature temporarily disabled',
        ], 501);
    }

    public function messages($chatId, Request $request)
    {
        return response()->json([
            'message' => 'Feature temporarily disabled',
        ], 501);
    }

    /**
     * Get latest messages for a chat
     */
    public function latestMessages($chatId, Request $request)
    {
        try {
            $user = $request->user();
            
            // Verify user has access to this chat
            $chatAccess = DB::select("
                SELECT 1 FROM chat_user 
                WHERE chat_id = ? AND user_id = ?
            ", [$chatId, $user->id]);
            
            if (empty($chatAccess)) {
                return response()->json(['error' => 'Access denied'], 403);
            }
            
            // Get latest messages from whatsapp_messages table
            $messages = DB::select("
                SELECT 
                    m.id,
                    m.content,
                    u.name as sender,
                    m.chat_id as chat,
                    m.created_at,
                    m.updated_at,
                    m.type,
                    'inbound' as direction,
                    m.status
                FROM whatsapp_messages m
                LEFT JOIN users u ON m.sender_id = u.id
                WHERE m.chat_id = ?
                ORDER BY m.created_at DESC
                LIMIT 50
            ", [$chatId]);
            
            // Format messages
            $formattedMessages = [];
            foreach ($messages as $message) {
                $formattedMessages[] = [
                    'id' => $message->id,
                    'content' => $message->content,
                    'sender_id' => $message->sender,
                    'sender_name' => $message->sender,
                    'chat_id' => $message->chat,
                    'created_at' => $message->created_at,
                    'updated_at' => $message->updated_at,
                    'type' => $message->type,
                    'direction' => $message->direction,
                    'status' => $message->status ?? 'sent'
                ];
            }
            
            \Log::info('Latest messages fetched for chat', [
                'chat_id' => $chatId,
                'user_id' => $user->id,
                'messages_count' => count($formattedMessages)
            ]);
            
            return response()->json([
                'data' => array_reverse($formattedMessages), // Reverse to get chronological order
                'total' => count($formattedMessages)
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error fetching latest messages: ' . $e->getMessage(), [
                'chat_id' => $chatId,
                'user_id' => $request->user()->id ?? 'unknown',
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Failed to fetch messages',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
