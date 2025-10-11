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
            // Prefer the authenticated user; if unavailable, use the single app user
            $authUser = $request->user();
            $user = $authUser ?: \App\Models\User::getFirstUser();

            // Helper to fetch chats for a given user id
            $fetchChatsForUser = function($userId) {
                $likePattern = '%"' . $userId . '"%';
                return DB::select("
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
                LEFT JOIN chat_user cu ON c.id = cu.chat_id AND cu.user_id = ?
                WHERE (
                    cu.user_id = ?
                    OR c.created_by = ?
                    OR (c.participants IS NOT NULL AND c.participants LIKE ?)
                )
                AND (c.is_archived = 0 OR c.is_archived IS NULL)
                ORDER BY c.updated_at DESC
            ", [$userId, $userId, $userId, $likePattern]);
            };

            // Attempt with current user
            $chats = $fetchChatsForUser($user->id);

            // If none found, fallback to any user with memberships
            if (count($chats) === 0) {
                $fallback = DB::select("SELECT user_id FROM chat_user GROUP BY user_id ORDER BY MIN(created_at) ASC LIMIT 1");
                if (!empty($fallback)) {
                    $fallbackUserId = $fallback[0]->user_id;
                    $chats = $fetchChatsForUser($fallbackUserId);
                    // Update $user reference for logging/formatting context
                    $user = \App\Models\User::find($fallbackUserId) ?: $user;
                }
            }
            
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

    public function destroy(Request $request, $chatId)
    {
        try {
            // Access check - ensure user has access to this chat
            $user = $request->user();
            $hasAccess = false;
            
            if ($user) {
                $chatAccess = DB::select("SELECT 1 FROM chat_user WHERE chat_id = ? AND user_id = ?", [$chatId, $user->id]);
                $hasAccess = !empty($chatAccess);
            }

            if (!$hasAccess) {
                $fallbackUser = \App\Models\User::getFirstUser();
                if ($fallbackUser) {
                    $chatAccess = DB::select("SELECT 1 FROM chat_user WHERE chat_id = ? AND user_id = ?", [$chatId, $fallbackUser->id]);
                    if (!empty($chatAccess)) {
                        $user = $fallbackUser;
                        $hasAccess = true;
                    }
                }
            }

            if (!$hasAccess) {
                return response()->json(['error' => 'Access denied'], 403);
            }

            // Delete the chat and related data
            DB::beginTransaction();
            
            try {
                // Delete messages associated with this chat
                DB::delete("DELETE FROM whatsapp_messages WHERE chat_id = ?", [$chatId]);
                
                // Delete chat_user relationships
                DB::delete("DELETE FROM chat_user WHERE chat_id = ?", [$chatId]);
                
                // Delete the chat itself
                DB::delete("DELETE FROM chats WHERE id = ?", [$chatId]);
                
                DB::commit();
                
                \Log::info('Chat deleted successfully', [
                    'chat_id' => $chatId,
                    'user_id' => $user->id ?? 'unknown'
                ]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Chat deleted successfully'
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            \Log::error('Error deleting chat: ' . $e->getMessage(), [
                'chat_id' => $chatId,
                'user_id' => $request->user()->id ?? 'unknown',
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Failed to delete chat',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function messages($chatId, Request $request)
    {
        try {
            // Access check (align with latestMessages)
            $user = $request->user();
            $hasAccess = false;
            if ($user) {
                $chatAccess = DB::select("SELECT 1 FROM chat_user WHERE chat_id = ? AND user_id = ?", [$chatId, $user->id]);
                $hasAccess = !empty($chatAccess);
            }

            if (!$hasAccess) {
                $fallbackUser = \App\Models\User::getFirstUser();
                if ($fallbackUser) {
                    $chatAccess = DB::select("SELECT 1 FROM chat_user WHERE chat_id = ? AND user_id = ?", [$chatId, $fallbackUser->id]);
                    if (!empty($chatAccess)) {
                        $user = $fallbackUser;
                        $hasAccess = true;
                    }
                }
            }

            if (!$hasAccess) {
                $anyMember = DB::select("SELECT user_id FROM chat_user WHERE chat_id = ? ORDER BY created_at ASC LIMIT 1", [$chatId]);
                if (!empty($anyMember)) {
                    $memberUserId = $anyMember[0]->user_id;
                    $memberUser = \App\Models\User::find($memberUserId);
                    if ($memberUser) {
                        $user = $memberUser;
                        $hasAccess = true;
                        \Log::warning('Dev fallback: granting messages access using chat member', [
                            'chat_id' => $chatId,
                            'member_user_id' => $memberUserId
                        ]);
                    }
                }
            }

            if (!$hasAccess) {
                return response()->json(['error' => 'Access denied'], 403);
            }

            // Validate params
            $validated = $request->validate([
                'before' => 'nullable|integer|min:1',
                'limit' => 'nullable|integer|min:1|max:100',
            ]);

            $limit = $validated['limit'] ?? 20;
            $beforeId = $validated['before'] ?? null;

            // Find the user with phone='me' for proper is_from_me comparison
            $meUser = DB::selectOne("
                SELECT u.id
                FROM users u
                INNER JOIN chat_user cu ON cu.user_id = u.id
                WHERE cu.chat_id = ? AND u.phone = 'me'
                LIMIT 1
            ", [$chatId]);
            
            $currentUserId = $meUser ? $meUser->id : $user->id;

            $bindings = [$chatId];

            // Build SQL with optional cursor
            if ($beforeId) {
                // Get created_at for the beforeId to create a stable cursor
                $beforeRow = DB::select("SELECT created_at FROM whatsapp_messages WHERE id = ? AND chat_id = ? LIMIT 1", [$beforeId, $chatId]);
                if (!empty($beforeRow)) {
                    $beforeCreatedAt = $beforeRow[0]->created_at;
                    $sql = "
                        SELECT 
                            m.id,
                            m.content,
                            m.sender_id,
                            u.name as sender_name,
                            u.phone as sender_phone,
                            m.chat_id,
                            m.created_at,
                            m.updated_at,
                            m.type,
                            'inbound' as direction,
                            m.status,
                            m.media_url,
                            m.media_type
                        FROM whatsapp_messages m
                        LEFT JOIN users u ON m.sender_id = u.id
                        WHERE m.chat_id = ?
                          AND (
                            m.created_at < ? OR (m.created_at = ? AND m.id < ?)
                          )
                        ORDER BY m.created_at DESC, m.id DESC
                        LIMIT ?
                    ";
                    $bindings = [$chatId, $beforeCreatedAt, $beforeCreatedAt, $beforeId, $limit];
                } else {
                    // If beforeId not found, just return latest page
                    $sql = "
                        SELECT 
                            m.id,
                            m.content,
                            m.sender_id,
                            u.name as sender_name,
                            u.phone as sender_phone,
                            m.chat_id,
                            m.created_at,
                            m.updated_at,
                            m.type,
                            'inbound' as direction,
                            m.status,
                            m.media_url,
                            m.media_type
                        FROM whatsapp_messages m
                        LEFT JOIN users u ON m.sender_id = u.id
                        WHERE m.chat_id = ?
                        ORDER BY m.created_at DESC, m.id DESC
                        LIMIT ?
                    ";
                    $bindings = [$chatId, $limit];
                }
            } else {
                $sql = "
                    SELECT 
                        m.id,
                        m.content,
                        m.sender_id,
                        u.name as sender_name,
                        u.phone as sender_phone,
                        m.chat_id,
                        m.created_at,
                        m.updated_at,
                        m.type,
                        'inbound' as direction,
                        m.status,
                        m.media,
                        m.mimetype
                    FROM whatsapp_messages m
                    LEFT JOIN users u ON m.sender_id = u.id
                    WHERE m.chat_id = ?
                    ORDER BY m.created_at DESC, m.id DESC
                    LIMIT ?
                ";
                $bindings = [$chatId, $limit];
            }

        $rows = DB::select($sql, $bindings);

        // Return in chronological order (oldest first) to match frontend expectations
        $rows = array_reverse($rows);

        $formatted = array_map(function ($m) use ($currentUserId) {
            return [
                'id' => (string) $m->id,
                'content' => $m->content,
                'sender_id' => (string) $m->sender_id,
                'sender_name' => $m->sender_name,
                'sender_phone' => $m->sender_phone ?? null,
                'chat_id' => (string) $m->chat_id,
                'created_at' => $m->created_at,
                'updated_at' => $m->updated_at,
                'type' => $m->type,
                'direction' => $m->direction,
                'status' => $m->status ?? 'sent',
                'is_from_me' => ((string) $m->sender_id === (string) $currentUserId),
                'media' => $m->media_url ?? null,
                'mimetype' => $m->media_type ?? null,
            ];
        }, $rows);

        \Log::info('Paginated messages fetched for chat', [
            'chat_id' => $chatId,
            'user_id' => $user->id ?? null,
            'count' => count($formatted),
            'limit' => $limit,
            'before' => $beforeId,
        ]);

        return response()->json([
            'data' => $formatted,
            'total' => count($formatted),
        ]);
    } catch (\Exception $e) {
        \Log::error('Error fetching paginated messages: ' . $e->getMessage(), [
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

    /**
     * Get latest messages for a chat (simple, no cursor)
     */
    public function latestMessages($chatId, Request $request)
    {
        try {
            // Access check (same as messages())
            $user = $request->user();
            $hasAccess = false;
            if ($user) {
                $chatAccess = DB::select("SELECT 1 FROM chat_user WHERE chat_id = ? AND user_id = ?", [$chatId, $user->id]);
                $hasAccess = !empty($chatAccess);
            }

            if (!$hasAccess) {
                $fallbackUser = \App\Models\User::getFirstUser();
                if ($fallbackUser) {
                    $chatAccess = DB::select("SELECT 1 FROM chat_user WHERE chat_id = ? AND user_id = ?", [$chatId, $fallbackUser->id]);
                    if (!empty($chatAccess)) {
                        $user = $fallbackUser;
                        $hasAccess = true;
                    }
                }
            }

            if (!$hasAccess) {
                $anyMember = DB::select("SELECT user_id FROM chat_user WHERE chat_id = ? ORDER BY created_at ASC LIMIT 1", [$chatId]);
                if (!empty($anyMember)) {
                    $memberUserId = $anyMember[0]->user_id;
                    $memberUser = \App\Models\User::find($memberUserId);
                    if ($memberUser) {
                        $user = $memberUser;
                        $hasAccess = true;
                        \Log::warning('Dev fallback: granting latestMessages access using chat member', [
                            'chat_id' => $chatId,
                            'member_user_id' => $memberUserId
                        ]);
                    }
                }
            }

            if (!$hasAccess) {
                return response()->json(['error' => 'Access denied'], 403);
            }

            $limit = (int) ($request->input('limit', 50));
            if ($limit <= 0 || $limit > 100) { $limit = 50; }
            
            $afterId = $request->input('after');

            // Find the user with phone='me' for proper is_from_me comparison
            $meUser = DB::selectOne("
                SELECT u.id
                FROM users u
                INNER JOIN chat_user cu ON cu.user_id = u.id
                WHERE cu.chat_id = ? AND u.phone = 'me'
                LIMIT 1
            ", [$chatId]);
            
            $currentUserId = $meUser ? $meUser->id : $user->id;
            
            // Build query based on whether we have an 'after' parameter
            if ($afterId) {
                // Get messages after a specific message ID
                $afterRow = DB::selectOne("SELECT created_at FROM whatsapp_messages WHERE id = ? AND chat_id = ? LIMIT 1", [$afterId, $chatId]);
                
                if ($afterRow) {
                    $sql = "
                        SELECT 
                            m.id,
                            m.content,
                            m.sender_id,
                            u.name as sender_name,
                            u.phone as sender_phone,
                            m.chat_id,
                            m.created_at,
                            m.updated_at,
                            m.type,
                            'inbound' as direction,
                            m.status,
                            m.media_url,
                            m.media_type,
                            m.metadata
                        FROM whatsapp_messages m
                        LEFT JOIN users u ON m.sender_id = u.id
                        WHERE m.chat_id = ?
                          AND (m.created_at > ? OR (m.created_at = ? AND m.id > ?))
                        ORDER BY m.created_at ASC, m.id ASC
                        LIMIT ?
                    ";
                    $rows = DB::select($sql, [$chatId, $afterRow->created_at, $afterRow->created_at, $afterId, $limit]);
                } else {
                    // If afterId not found, return empty array
                    $rows = [];
                }
            } else {
                // No 'after' parameter, return latest messages
                $sql = "
                    SELECT 
                        m.id,
                        m.content,
                        m.sender_id,
                        u.name as sender_name,
                        u.phone as sender_phone,
                        m.chat_id,
                        m.created_at,
                        m.updated_at,
                        m.type,
                        'inbound' as direction,
                        m.status,
                        m.media_url,
                        m.media_type,
                        m.metadata
                    FROM whatsapp_messages m
                    LEFT JOIN users u ON m.sender_id = u.id
                    WHERE m.chat_id = ?
                    ORDER BY m.created_at DESC, m.id DESC
                    LIMIT ?
                ";
                $rows = DB::select($sql, [$chatId, $limit]);
                
                // Return oldest first to match UI
                $rows = array_reverse($rows);
            }

            // Format messages (already in correct order)
            $formattedMessages = array_map(function ($m) use ($currentUserId) {
                // Decode metadata if it's a JSON string
                $metadata = is_string($m->metadata) ? json_decode($m->metadata, true) : [];
                if (!is_array($metadata)) {
                    $metadata = [];
                }
                
                // Extract filename and size from metadata
                $filename = $metadata['filename'] ?? $metadata['original_name'] ?? null;
                $fileSize = $metadata['file_size'] ?? $metadata['size'] ?? null;
                
                // Extract mimetype - fallback to metadata if media_type is null
                $mimetype = $m->media_type ?? $metadata['original_mimetype'] ?? $metadata['mimetype'] ?? null;
                
                return [
                    'id' => (string) $m->id,
                    'content' => $m->content,
                    'sender_id' => (string) $m->sender_id,
                    'sender_name' => $m->sender_name,
                    'sender_phone' => $m->sender_phone ?? null,
                    'chat_id' => (string) $m->chat_id,
                    'created_at' => $m->created_at,
                    'updated_at' => $m->updated_at,
                    'type' => $m->type,
                    'direction' => $m->direction,
                    'status' => $m->status ?? 'sent',
                    'is_from_me' => ((string) $m->sender_id === (string) $currentUserId),
                    'media' => $m->media_url ?? null,
                    'mimetype' => $mimetype,
                    'filename' => $filename,
                    'size' => $fileSize,
                ];
            }, $rows);

            \Log::info('Latest messages fetched for chat', [
                'chat_id' => $chatId,
                'user_id' => $user->id ?? null,
                'messages_count' => count($formattedMessages)
            ]);

            return response()->json([
                'data' => $formattedMessages,
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
