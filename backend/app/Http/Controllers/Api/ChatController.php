<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    protected ChatService $chatService;

    public function __construct(ChatService $chatService)
    {
        $this->chatService = $chatService;
    }

    /**
     * Get all chats for the authenticated user.
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user() ?: User::getFirstUser();
            $chats = $this->chatService->getChatsForUser($user);

            \Log::info('Chats fetched for user', [
                'user_id' => $user->id,
                'chats_count' => count($chats),
            ]);

            return response()->json([
                'data' => $chats,
                'total' => count($chats),
                'per_page' => count($chats),
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
     * Create or update a chat (for contacts)
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'participants' => 'required|array',
                'participants.*' => 'string',
                'is_group' => 'sometimes|boolean',
            ]);
            
            $isGroup = $validated['is_group'] ?? false;
            $participants = $validated['participants'];
            
            // For direct chats, find or create based on participants
            if (!$isGroup && count($participants) === 1) {
                // Get the WhatsApp JID (the participant that's not 'me')
                $whatsappJid = $participants[0];
                
                // Extract the phone number part (before @) to handle different formats
                $phoneNumber = preg_replace('/@.*$/', '', $whatsappJid);
                
                // Add 'me' as the second participant
                $participants[] = 'me';
                sort($participants);
                
                // Find existing chat by whatsapp_id in metadata
                // We need to check for different format variations
                $chat = Chat::where('is_group', false)
                    ->get()
                    ->first(function($c) use ($phoneNumber) {
                        $metadata = is_string($c->metadata) ? json_decode($c->metadata, true) : $c->metadata;
                        if (!$metadata || !isset($metadata['whatsapp_id'])) {
                            return false;
                        }
                        // Extract phone number from stored whatsapp_id
                        $storedPhone = preg_replace('/@.*$/', '', $metadata['whatsapp_id']);
                        return $storedPhone === $phoneNumber;
                    });
                
                if ($chat) {
                    // Update existing chat name, participants, and normalize metadata
                    $metadata = $chat->metadata ? (is_array($chat->metadata) ? $chat->metadata : json_decode($chat->metadata, true)) : [];
                    $metadata['whatsapp_id'] = $whatsappJid; // Normalize to the new format
                    
                    $chat->update([
                        'name' => $validated['name'],
                        'participants' => $participants,
                        'metadata' => $metadata
                    ]);
                } else {
                    // Create new chat
                    $user = User::getFirstUser();
                    
                    // If the name looks like a WhatsApp JID, format it as a phone number
                    $displayName = $validated['name'];
                    if (preg_match('/^(\d+)@s\.whatsapp\.net$/', $displayName, $matches)) {
                        // It's a WhatsApp JID, format as +number
                        $displayName = '+' . $matches[1];
                    }
                    
                    $chat = Chat::create([
                        'name' => $displayName,
                        'is_group' => false,
                        'participants' => $participants,
                        'created_by' => $user->id,
                        'metadata' => [
                            'whatsapp_id' => $whatsappJid,
                            'created_by' => $user->id
                        ]
                    ]);
                    
                    // Attach the app user to the chat
                    $chat->users()->attach($user->id);
                }
            } else {
                // Group chat creation
                $chat = Chat::create([
                    'name' => $validated['name'],
                    'is_group' => true,
                    'participants' => $participants,
                ]);
                
                $user = User::getFirstUser();
                $chat->users()->attach($user->id);
            }
            
            return response()->json([
                'status' => 'success',
                'message' => 'Chat created/updated successfully',
                'data' => $chat
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create chat: ' . $e->getMessage()
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

    public function update(Request $request, Chat $chat)
    {
        try {
            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'is_archived' => 'sometimes|boolean',
                'is_muted' => 'sometimes|boolean',
                'metadata' => 'sometimes|array',
            ]);
            
            $chat->update($validated);
            
            // Reload the model to get fresh data
            $chat->refresh();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Chat updated successfully',
                'data' => [
                    'id' => $chat->id,
                    'name' => $chat->name,
                    'is_group' => $chat->is_group,
                    'participants' => $chat->participants,
                    'updated_at' => $chat->updated_at,
                ]
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Chat update failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'chat_id' => $chat->id ?? null
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update chat: ' . $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null
            ], 500);
        }
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
        try {
            $chat = Chat::findOrFail($chatId);
            
            // Reset the unread count
            $chat->markAsRead();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Chat marked as read',
                'data' => [
                    'id' => $chat->id,
                    'unread_count' => $chat->unread_count
                ]
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Chat not found'
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Error marking chat as read: ' . $e->getMessage(), [
                'chat_id' => $chatId,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to mark chat as read: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve a pending chat
     */
    public function approve(Request $request, $chatId)
    {
        try {
            \Log::info('Approve chat request received', [
                'chat_id' => $chatId,
                'user_id' => $request->user()->id ?? 'none',
                'request_data' => $request->all()
            ]);
            
            $chat = Chat::findOrFail($chatId);
            
            \Log::info('Chat found, updating', [
                'chat_id' => $chat->id,
                'current_pending_approval' => $chat->pending_approval
            ]);
            
            $chat->update(['pending_approval' => false]);
            
            \Log::info('Chat updated successfully', [
                'chat_id' => $chat->id,
                'new_pending_approval' => $chat->pending_approval
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Chat approved successfully',
                'data' => [
                    'id' => $chat->id,
                    'name' => $chat->name,
                    'pending_approval' => $chat->pending_approval,
                    'is_group' => $chat->is_group,
                    'updated_at' => $chat->updated_at
                ]
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            \Log::error('Chat not found: ' . $e->getMessage(), [
                'chat_id' => $chatId
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Chat not found'
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Error approving chat: ' . $e->getMessage(), [
                'chat_id' => $chatId,
                'error_class' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to approve chat: ' . $e->getMessage(),
                'error_type' => get_class($e)
            ], 500);
        }
    }

    /**
     * Reject a pending chat (delete it and its messages)
     */
    public function reject(Request $request, $chatId)
    {
        try {
            DB::beginTransaction();
            
            try {
                // Get message IDs for this chat to delete message_reads
                $messageIds = DB::select("SELECT id FROM whatsapp_messages WHERE chat_id = ?", [$chatId]);
                $messageIdArray = array_column($messageIds, 'id');
                
                // Delete message_reads for messages in this chat
                if (!empty($messageIdArray)) {
                    $placeholders = implode(',', array_fill(0, count($messageIdArray), '?'));
                    DB::delete("DELETE FROM message_reads WHERE message_id IN ($placeholders)", $messageIdArray);
                }
                
                // Delete messages associated with this chat
                DB::delete("DELETE FROM whatsapp_messages WHERE chat_id = ?", [$chatId]);
                
                // Delete legacy messages if any
                $chat = DB::selectOne("SELECT metadata FROM chats WHERE id = ?", [$chatId]);
                if ($chat && $chat->metadata) {
                    $metadata = json_decode($chat->metadata, true);
                    if (isset($metadata['whatsapp_id'])) {
                        DB::delete("DELETE FROM messages WHERE chat = ?", [$metadata['whatsapp_id']]);
                    }
                }
                
                // Get users associated with this chat before deleting relationships
                $chatUsers = DB::select("SELECT user_id FROM chat_user WHERE chat_id = ?", [$chatId]);
                $chatUserIds = array_column($chatUsers, 'user_id');
                
                // Delete chat_user relationships
                DB::delete("DELETE FROM chat_user WHERE chat_id = ?", [$chatId]);
                
                // Delete the chat itself
                DB::delete("DELETE FROM chats WHERE id = ?", [$chatId]);
                
                // Clean up orphaned users (users with no remaining chats)
                // CRITICAL: Only delete WhatsApp-only users, NEVER admin/app users
                $mainUser = \App\Models\User::getFirstUser();
                $deletedUsers = 0;
                foreach ($chatUserIds as $userId) {
                    // SAFEGUARD 1: Skip the main app user
                    if ($mainUser && $userId == $mainUser->id) {
                        continue;
                    }
                    
                    // SAFEGUARD 2: Get user details to check if they're an admin/app user
                    $userToDelete = DB::selectOne(
                        "SELECT id, password, name FROM users WHERE id = ?", 
                        [$userId]
                    );
                    
                    if (!$userToDelete) {
                        continue; // User doesn't exist
                    }
                    
                    // SAFEGUARD 3: NEVER delete users named "Admin"
                    if ($userToDelete->name === 'Admin') {
                        \Log::warning('Prevented deletion of Admin user', [
                            'user_id' => $userId,
                            'name' => $userToDelete->name
                        ]);
                        continue;
                    }
                    
                    // SAFEGUARD 4: NEVER delete users with non-WhatsApp names
                    // Only auto-generated WhatsApp users have the name "WhatsApp User"
                    if ($userToDelete->name !== 'WhatsApp User') {
                        \Log::warning('Prevented deletion of non-WhatsApp user', [
                            'user_id' => $userId,
                            'name' => $userToDelete->name
                        ]);
                        continue;
                    }
                    
                    // Check if this user has any other chats
                    $hasOtherChats = DB::selectOne(
                        "SELECT COUNT(*) as count FROM chat_user WHERE user_id = ?", 
                        [$userId]
                    );
                    
                    // If user has no other chats, delete them (only WhatsApp-only users reach here)
                    if ($hasOtherChats && $hasOtherChats->count == 0) {
                        DB::delete("DELETE FROM users WHERE id = ?", [$userId]);
                        $deletedUsers++;
                        \Log::info('Deleted orphaned WhatsApp user', [
                            'user_id' => $userId,
                            'name' => $userToDelete->name
                        ]);
                    }
                }
                
                DB::commit();
                
                \Log::info('Pending chat rejected and deleted', [
                    'chat_id' => $chatId,
                    'deleted_messages' => count($messageIdArray),
                    'deleted_users' => $deletedUsers
                ]);
                
                return response()->json([
                    'status' => 'success',
                    'message' => 'Chat rejected and deleted successfully'
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            \Log::error('Error rejecting chat: ' . $e->getMessage(), [
                'chat_id' => $chatId,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to reject chat: ' . $e->getMessage()
            ], 500);
        }
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

            // If this is a WhatsApp group, ask the receiver to leave it before deletion
            try {
                $chatRow = DB::selectOne("SELECT metadata FROM chats WHERE id = ?", [$chatId]);
                if ($chatRow && $chatRow->metadata) {
                    $metadata = json_decode($chatRow->metadata, true) ?: [];
                    $whatsappId = $metadata['whatsapp_id'] ?? null;
                    if (is_string($whatsappId) && str_ends_with($whatsappId, '@g.us')) {
                        $receiverUrl = config('app.receiver_url', env('RECEIVER_URL', 'http://127.0.0.1:3000'));
                        $receiverUrl = rtrim($receiverUrl, '/');
                        $http = \Illuminate\Support\Facades\Http::timeout(10)
                            ->withHeaders([
                                'Accept' => 'application/json',
                                'Content-Type' => 'application/json',
                                'X-API-Key' => config('app.receiver_api_key', ''),
                            ]);
                        $isHttps = str_starts_with(strtolower($receiverUrl), 'https://');
                        $allowInsecure = (bool) env('RECEIVER_TLS_INSECURE', false);
                        if ($isHttps && $allowInsecure) {
                            $http = $http->withoutVerifying();
                        }
                        // Fire and forget - don't block deletion on failures
                        try {
                            $resp = $http->post("{$receiverUrl}/leave-group", [ 'groupJid' => $whatsappId ]);
                            if (!$resp->successful()) {
                                \Log::warning('Receiver failed to leave WhatsApp group', [
                                    'chat_id' => $chatId,
                                    'whatsapp_id' => $whatsappId,
                                    'status' => $resp->status(),
                                    'body' => $resp->body(),
                                ]);
                            }
                        } catch (\Throwable $e) {
                            \Log::warning('Error requesting group leave from receiver', [
                                'chat_id' => $chatId,
                                'whatsapp_id' => $whatsappId,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Non-fatal: proceed with deletion regardless
                \Log::debug('Skip group leave pre-step', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
            }

            // Delete the chat and related data
            DB::beginTransaction();
            
            try {
                // Get message IDs for this chat to delete message_reads
                $messageIds = DB::select("SELECT id FROM whatsapp_messages WHERE chat_id = ?", [$chatId]);
                $messageIdArray = array_column($messageIds, 'id');
                
                // Delete message_reads for messages in this chat
                if (!empty($messageIdArray)) {
                    $placeholders = implode(',', array_fill(0, count($messageIdArray), '?'));
                    DB::delete("DELETE FROM message_reads WHERE message_id IN ($placeholders)", $messageIdArray);
                }
                
                // Delete messages associated with this chat
                DB::delete("DELETE FROM whatsapp_messages WHERE chat_id = ?", [$chatId]);
                
                // Delete legacy messages if any (using chat field as string identifier)
                // Get the chat to find its WhatsApp ID
                $chat = DB::selectOne("SELECT metadata FROM chats WHERE id = ?", [$chatId]);
                if ($chat && $chat->metadata) {
                    $metadata = json_decode($chat->metadata, true);
                    if (isset($metadata['whatsapp_id'])) {
                        DB::delete("DELETE FROM messages WHERE chat = ?", [$metadata['whatsapp_id']]);
                    }
                }
                
                // Get users associated with this chat before deleting relationships
                $chatUsers = DB::select("SELECT user_id FROM chat_user WHERE chat_id = ?", [$chatId]);
                $chatUserIds = array_column($chatUsers, 'user_id');
                
                // Delete chat_user relationships
                DB::delete("DELETE FROM chat_user WHERE chat_id = ?", [$chatId]);
                
                // Delete the chat itself
                DB::delete("DELETE FROM chats WHERE id = ?", [$chatId]);
                
                // Clean up orphaned users (users with no remaining chats)
                // CRITICAL: Only delete WhatsApp-only users, NEVER admin/app users
                $mainUser = \App\Models\User::getFirstUser();
                $deletedUsers = 0;
                foreach ($chatUserIds as $userId) {
                    // SAFEGUARD 1: Skip the main app user
                    if ($mainUser && $userId == $mainUser->id) {
                        continue;
                    }
                    
                    // SAFEGUARD 2: Get user details to check if they're an admin/app user
                    $userToDelete = DB::selectOne(
                        "SELECT id, password, name FROM users WHERE id = ?", 
                        [$userId]
                    );
                    
                    if (!$userToDelete) {
                        continue; // User doesn't exist
                    }
                    
                    // SAFEGUARD 3: NEVER delete users named "Admin"
                    if ($userToDelete->name === 'Admin') {
                        \Log::warning('Prevented deletion of Admin user', [
                            'user_id' => $userId,
                            'name' => $userToDelete->name
                        ]);
                        continue;
                    }
                    
                    // SAFEGUARD 4: NEVER delete users with non-WhatsApp names
                    // Only auto-generated WhatsApp users have the name "WhatsApp User"
                    if ($userToDelete->name !== 'WhatsApp User') {
                        \Log::warning('Prevented deletion of non-WhatsApp user', [
                            'user_id' => $userId,
                            'name' => $userToDelete->name
                        ]);
                        continue;
                    }
                    
                    // Check if this user has any other chats
                    $hasOtherChats = DB::selectOne(
                        "SELECT COUNT(*) as count FROM chat_user WHERE user_id = ?", 
                        [$userId]
                    );
                    
                    // If user has no other chats, delete them (only WhatsApp-only users reach here)
                    if ($hasOtherChats && $hasOtherChats->count == 0) {
                        DB::delete("DELETE FROM users WHERE id = ?", [$userId]);
                        $deletedUsers++;
                        \Log::info('Deleted orphaned WhatsApp user', [
                            'user_id' => $userId,
                            'name' => $userToDelete->name
                        ]);
                    }
                }
                
                DB::commit();
                
                \Log::info('Chat deleted successfully', [
                    'chat_id' => $chatId,
                    'user_id' => $user->id ?? 'unknown',
                    'deleted_messages' => count($messageIdArray),
                    'deleted_users' => $deletedUsers
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
                            u.profile_picture_url as sender_profile_picture_url,
                            u.phone as sender_phone,
                            m.chat_id,
                            m.created_at,
                            m.updated_at,
                            m.delivered_at,
                            m.read_at,
                            m.read_by,
                            m.type,
                            'inbound' as direction,
                            m.status,
                            m.media_url,
                            m.media_type,
                            m.metadata,
                            m.reactions,
                            m.reply_to_message_id
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
                            u.profile_picture_url as sender_profile_picture_url,
                            u.phone as sender_phone,
                            m.chat_id,
                            m.created_at,
                            m.updated_at,
                            m.type,
                            'inbound' as direction,
                            m.status,
                            m.media_url,
                            m.media_type,
                            m.metadata,
                            m.reactions,
                            m.reply_to_message_id
                        FROM whatsapp_messages m
                        LEFT JOIN users u ON m.sender_id = u.id
                        LEFT JOIN chats c ON m.chat_id = c.id
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
                        u.profile_picture_url as sender_profile_picture_url,
                        u.phone as sender_phone,
                        m.chat_id,
                        m.created_at,
                        m.updated_at,
                        m.delivered_at,
                        m.read_at,
                        m.read_by,
                        m.type,
                        'inbound' as direction,
                        m.status,
                        m.media_url,
                        m.media_type,
                        m.metadata,
                        m.reactions,
                        m.reply_to_message_id
                    FROM whatsapp_messages m
                    LEFT JOIN users u ON m.sender_id = u.id
                    LEFT JOIN chats c ON m.chat_id = c.id
                    WHERE m.chat_id = ?
                    ORDER BY m.created_at DESC, m.id DESC
                    LIMIT ?
                ";
                $bindings = [$chatId, $limit];
            }

            // Execute the query and return in chronological order (oldest first)
            $rows = DB::select($sql, $bindings);
            $rows = array_reverse($rows);

            // Load poll votes without using JSON aggregation (more compatible with MariaDB/MySQL variants)
            $pollVotesByMessageId = [];
            $messageIds = array_values(array_unique(array_map(fn ($row) => (int) $row->id, $rows)));

            $receiptStatusesByMessageId = [];
            if (!empty($messageIds)) {
                $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
                $receiptRows = DB::select(
                    "SELECT message_id, participant_id, delivered_at, read_at FROM message_receipts WHERE message_id IN ($placeholders)",
                    $messageIds
                );
                foreach ($receiptRows as $r) {
                    $mid = (int) $r->message_id;
                    if (!isset($receiptStatusesByMessageId[$mid])) {
                        $receiptStatusesByMessageId[$mid] = [];
                    }
                    $receiptStatusesByMessageId[$mid][] = [
                        'participant_id' => (string) $r->participant_id,
                        'delivered_at' => $r->delivered_at,
                        'read_at' => $r->read_at,
                    ];
                }
            }

            if (!empty($messageIds)) {
                $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
                $votes = DB::select(
                    "SELECT message_id, user_id, option_index, voted_at FROM poll_votes WHERE message_id IN ($placeholders)",
                    $messageIds
                );

                foreach ($votes as $vote) {
                    $mid = (int) $vote->message_id;
                    if (!isset($pollVotesByMessageId[$mid])) {
                        $pollVotesByMessageId[$mid] = [];
                    }
                    $pollVotesByMessageId[$mid][] = [
                        'user_id' => $vote->user_id,
                        'option_index' => $vote->option_index,
                        'voted_at' => $vote->voted_at,
                    ];
                }
            }

        $formatted = array_map(function ($m) use ($currentUserId, $pollVotesByMessageId, $receiptStatusesByMessageId) {
            // Decode metadata if it's a JSON string
            $metadata = isset($m->metadata) && is_string($m->metadata) ? json_decode($m->metadata, true) : [];
            if (!is_array($metadata)) {
                $metadata = [];
            }

            $readBy = [];
            if (isset($m->read_by)) {
                $readBy = is_string($m->read_by) ? json_decode($m->read_by, true) : $m->read_by;
                if (!is_array($readBy)) {
                    $readBy = [];
                }
            }
            
            // Debug logging for poll messages
            if ($m->type === 'poll') {
                \Log::debug('Poll message metadata debug', [
                    'message_id' => $m->id,
                    'metadata_raw' => $m->metadata,
                    'metadata_decoded' => $metadata,
                    'has_poll_data' => isset($metadata['poll_data'])
                ]);
            }
            
            // Decode reactions if it's a JSON string
            $reactions = null;
            $reactionUsers = null;
            if (isset($m->reactions)) {
                $reactions = is_string($m->reactions) ? json_decode($m->reactions, true) : $m->reactions;
                if (!is_array($reactions)) {
                    $reactions = null;
                } else {
                    // Build reaction_users mapping: user_id => name or phone
                    $reactionUsers = [];
                    $userIds = array_keys($reactions);
                    if (!empty($userIds)) {
                        // First, get the chat info to determine if it's a group chat
                        $chatInfo = DB::selectOne("SELECT is_group FROM chats WHERE id = ?", [$m->chat_id]);
                        $isGroupChat = $chatInfo && $chatInfo->is_group;
                        
                        if ($isGroupChat) {
                            // For group chats, try to get contact names from chats table first
                            $users = DB::select("
                                SELECT u.id, u.name, u.phone, c.name as contact_name
                                FROM users u
                                LEFT JOIN chats c ON (
                                    c.is_group = false 
                                    AND c.participants LIKE CONCAT('%\"', u.phone, '\"%')
                                )
                                WHERE u.id IN (" . implode(',', array_map('intval', $userIds)) . ")
                            ");
                            
                            foreach ($users as $user) {
                                $displayName = null;
                                
                                // Prefer contact name from chats table if available and not a phone number
                                if ($user->contact_name && $user->contact_name !== $user->phone) {
                                    $isPhoneNumber = preg_match('/^[+\d\s\-_@.]+$/', $user->contact_name);
                                    if (!$isPhoneNumber) {
                                        $displayName = $user->contact_name;
                                    }
                                }
                                
                                // Fallback to user name if it's not a placeholder
                                if (!$displayName && $user->name && strtolower($user->name) !== 'whatsapp user' && strtolower($user->name) !== 'temporary user') {
                                    $displayName = $user->name;
                                }
                                
                                // Last resort: format phone number
                                if (!$displayName && $user->phone) {
                                    $phone = preg_replace('/@.*$/', '', $user->phone);
                                    $phone = preg_match('/^\d+$/', $phone) ? '+' . $phone : $phone;
                                    $displayName = $phone;
                                }
                                
                                $reactionUsers[(string)$user->id] = $displayName ?: 'Unknown';
                            }
                        } else {
                            // For individual chats, use the original logic
                            $users = DB::select("
                                SELECT id, name, phone
                                FROM users
                                WHERE id IN (" . implode(',', array_map('intval', $userIds)) . ")
                            ");
                            foreach ($users as $user) {
                                // Prefer name if it's not a placeholder, otherwise use formatted phone
                                if ($user->name && strtolower($user->name) !== 'whatsapp user' && strtolower($user->name) !== 'temporary user') {
                                    $reactionUsers[(string)$user->id] = $user->name;
                                } elseif ($user->phone) {
                                    // Format phone number: remove domain and add + prefix
                                    $phone = preg_replace('/@.*$/', '', $user->phone);
                                    $phone = preg_match('/^\d+$/', $phone) ? '+' . $phone : $phone;
                                    $reactionUsers[(string)$user->id] = $phone;
                                } else {
                                    $reactionUsers[(string)$user->id] = 'Unknown';
                                }
                            }
                        }
                    }
                }
            }
            
            $pollVotes = $pollVotesByMessageId[(int) $m->id] ?? null;
            
            // Extract filename and size from metadata
            $filename = $metadata['filename'] ?? $metadata['original_name'] ?? null;
            $fileSize = $metadata['file_size'] ?? $metadata['size'] ?? null;
            
            // Load quoted message if this is a reply
            $quotedMessage = null;
            if (!empty($m->reply_to_message_id)) {
                $quoted = DB::selectOne("
                    SELECT m.id, m.content, m.type, m.sender_id, m.chat_id,
                        CASE 
                            WHEN u.name = 'WhatsApp User' THEN c.name
                            ELSE u.name
                        END as sender_name,
                        u.phone as sender
                    FROM whatsapp_messages m
                    LEFT JOIN users u ON m.sender_id = u.id
                    LEFT JOIN chats c ON m.chat_id = c.id
                    WHERE m.id = ?
                    LIMIT 1
                ", [$m->reply_to_message_id]);
                
                if ($quoted) {
                    $quotedSender = $quoted->sender ?? null;
                    if (is_string($quotedSender)) {
                        $quotedSenderLower = strtolower($quotedSender);
                        if (str_contains($quotedSenderLower, 'promise') || str_contains($quotedSenderLower, '[object')) {
                            $quotedSender = null;
                        }
                    }
                    $quotedMessage = [
                        'id' => (string) $quoted->id,
                        'content' => $quoted->content,
                        'type' => $quoted->type,
                        'sender' => $quotedSender ?? 'Unknown',
                        'sender_name' => $quoted->sender_name,
                    ];
                }
            }
            
            // Derive better sender name if placeholder
            $rawSenderName = $m->sender_name ?? null;
            $senderName = $rawSenderName;
            if (!$senderName || strtolower($senderName) === 'whatsapp user') {
                $senderName = $metadata['senderName']
                    ?? $metadata['sender_name']
                    ?? $metadata['name']
                    ?? $metadata['displayName']
                    ?? $metadata['pushName']
                    ?? $rawSenderName; // keep original if nothing better
            }

            // Derive sender avatar
            $senderAvatarUrl = $m->sender_profile_picture_url
                ?? ($metadata['senderProfilePictureUrl'] ?? ($metadata['sender_profile_picture_url'] ?? ($metadata['sender_avatar_url'] ?? ($metadata['profile_picture_url'] ?? null))));

            $senderPhone = $m->sender_phone ?? null;
            if (is_string($senderPhone)) {
                $senderPhoneLower = strtolower($senderPhone);
                if (str_contains($senderPhoneLower, 'promise') || str_contains($senderPhoneLower, '[object')) {
                    $senderPhone = null;
                }
            }

            if (!$senderPhone && (is_string($senderName) && (strtolower($senderName) === 'whatsapp user' || strtolower($senderName) === 'temporary user'))) {
                $senderName = 'Unknown';
            }

            return [
                'id' => (string) $m->id,
                'content' => $m->content,
                'sender_id' => (string) $m->sender_id,
                'sender_name' => $senderName,
                'sender_phone' => $senderPhone,
                'chat_id' => (string) $m->chat_id,
                'created_at' => $m->created_at,
                'updated_at' => $m->updated_at,
                'delivered_at' => $m->delivered_at ?? null,
                'read_at' => $m->read_at ?? null,
                'read_by' => $readBy,
                'type' => $m->type,
                'direction' => $m->direction,
                'status' => $m->status ?? 'sent',
                'is_from_me' => ((string) $m->sender_id === (string) $currentUserId),
                'media' => $m->media_url ?? null,
                'mimetype' => $m->media_type ?? null,
                'filename' => $filename,
                'size' => $fileSize,
                'metadata' => $metadata,
                'reactions' => $reactions,
                'reaction_users' => $reactionUsers,
                'reply_to_message_id' => $m->reply_to_message_id ?? null,
                'quoted_message' => $quotedMessage,
                'sender_avatar_url' => $senderAvatarUrl,
                'poll_votes' => $pollVotes,
                'receipt_statuses' => $receiptStatusesByMessageId[(int) $m->id] ?? [],
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
                            CASE WHEN m.deleted_at IS NOT NULL THEN '[Gelöschte Nachricht]' ELSE m.content END as content,
                            m.sender_id,
                            u.name as sender_name,
                            u.profile_picture_url as sender_profile_picture_url,
                            u.phone as sender_phone,
                            m.chat_id,
                            m.created_at,
                            m.updated_at,
                            m.delivered_at,
                            m.read_at,
                            m.read_by,
                            m.deleted_at,
                            m.edited_at,
                            CASE WHEN m.deleted_at IS NOT NULL THEN 'deleted' ELSE m.type END as type,
                            'inbound' as direction,
                            m.status,
                            m.media_url,
                            m.media_type,
                            m.metadata,
                            m.reactions,
                            m.reply_to_message_id
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
                        CASE WHEN m.deleted_at IS NOT NULL THEN '[Gelöschte Nachricht]' ELSE m.content END as content,
                        m.sender_id,
                        u.name as sender_name,
                        u.profile_picture_url as sender_profile_picture_url,
                        u.phone as sender_phone,
                        m.chat_id,
                        m.created_at,
                        m.updated_at,
                        m.delivered_at,
                        m.read_at,
                        m.read_by,
                        m.deleted_at,
                        m.edited_at,
                        CASE WHEN m.deleted_at IS NOT NULL THEN 'deleted' ELSE m.type END as type,
                        'inbound' as direction,
                        m.status,
                        m.media_url,
                        m.media_type,
                        m.metadata,
                        m.reactions,
                        m.reply_to_message_id
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

            // Load poll votes without using JSON aggregation (more compatible with MariaDB/MySQL variants)
            $pollVotesByMessageId = [];
            $messageIds = array_values(array_unique(array_map(fn ($row) => (int) $row->id, $rows)));

            $receiptStatusesByMessageId = [];
            if (!empty($messageIds)) {
                $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
                $receiptRows = DB::select(
                    "SELECT message_id, participant_id, delivered_at, read_at FROM message_receipts WHERE message_id IN ($placeholders)",
                    $messageIds
                );
                foreach ($receiptRows as $r) {
                    $mid = (int) $r->message_id;
                    if (!isset($receiptStatusesByMessageId[$mid])) {
                        $receiptStatusesByMessageId[$mid] = [];
                    }
                    $receiptStatusesByMessageId[$mid][] = [
                        'participant_id' => (string) $r->participant_id,
                        'delivered_at' => $r->delivered_at,
                        'read_at' => $r->read_at,
                    ];
                }
            }
            if (!empty($messageIds)) {
                $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
                $votes = DB::select(
                    "SELECT message_id, user_id, option_index, voted_at FROM poll_votes WHERE message_id IN ($placeholders)",
                    $messageIds
                );

                foreach ($votes as $vote) {
                    $mid = (int) $vote->message_id;
                    if (!isset($pollVotesByMessageId[$mid])) {
                        $pollVotesByMessageId[$mid] = [];
                    }
                    $pollVotesByMessageId[$mid][] = [
                        'user_id' => $vote->user_id,
                        'option_index' => $vote->option_index,
                        'voted_at' => $vote->voted_at,
                    ];
                }
            }

            // Format messages (already in correct order)
            $formattedMessages = array_map(function ($m) use ($currentUserId, $pollVotesByMessageId, $receiptStatusesByMessageId) {
                // Decode metadata if it's a JSON string
                $metadata = is_string($m->metadata) ? json_decode($m->metadata, true) : [];
                if (!is_array($metadata)) {
                    $metadata = [];
                }

                $readBy = [];
                if (isset($m->read_by)) {
                    $readBy = is_string($m->read_by) ? json_decode($m->read_by, true) : $m->read_by;
                    if (!is_array($readBy)) {
                        $readBy = [];
                    }
                }
                
                // Decode reactions if it's a JSON string
                $reactions = null;
                $reactionUsers = null;
                if (isset($m->reactions)) {
                    $reactions = is_string($m->reactions) ? json_decode($m->reactions, true) : $m->reactions;
                    if (!is_array($reactions)) {
                        $reactions = null;
                    } else {
                        // Build reaction_users mapping: user_id => name or phone
                        $reactionUsers = [];
                        $userIds = array_keys($reactions);
                        if (!empty($userIds)) {
                            // First, get the chat info to determine if it's a group chat
                            $chatInfo = DB::selectOne("SELECT is_group FROM chats WHERE id = ?", [$m->chat_id]);
                            $isGroupChat = $chatInfo && $chatInfo->is_group;
                            
                            if ($isGroupChat) {
                                // For group chats, try to get contact names from chats table first
                                $users = DB::select("
                                    SELECT u.id, u.name, u.phone, c.name as contact_name
                                    FROM users u
                                    LEFT JOIN chats c ON (
                                        c.is_group = false 
                                        AND c.participants LIKE CONCAT('%\"', u.phone, '\"%')
                                    )
                                    WHERE u.id IN (" . implode(',', array_map('intval', $userIds)) . ")
                                ");
                                
                                foreach ($users as $user) {
                                    $displayName = null;
                                    
                                    // Prefer contact name from chats table if available and not a phone number
                                    if ($user->contact_name && $user->contact_name !== $user->phone) {
                                        $isPhoneNumber = preg_match('/^[+\d\s\-_@.]+$/', $user->contact_name);
                                        if (!$isPhoneNumber) {
                                            $displayName = $user->contact_name;
                                        }
                                    }
                                    
                                    // Fallback to user name if it's not a placeholder
                                    if (!$displayName && $user->name && strtolower($user->name) !== 'whatsapp user' && strtolower($user->name) !== 'temporary user') {
                                        $displayName = $user->name;
                                    }
                                    
                                    // Last resort: format phone number
                                    if (!$displayName && $user->phone) {
                                        $phone = preg_replace('/@.*$/', '', $user->phone);
                                        $phone = preg_match('/^\d+$/', $phone) ? '+' . $phone : $phone;
                                        $displayName = $phone;
                                    }
                                    
                                    $reactionUsers[(string)$user->id] = $displayName ?: 'Unknown';
                                }
                            } else {
                                // For individual chats, use the original logic
                                $users = DB::select("
                                    SELECT id, name, phone
                                    FROM users
                                    WHERE id IN (" . implode(',', array_map('intval', $userIds)) . ")
                                ");
                                foreach ($users as $user) {
                                    // Prefer name if it's not a placeholder, otherwise use formatted phone
                                    if ($user->name && strtolower($user->name) !== 'whatsapp user' && strtolower($user->name) !== 'temporary user') {
                                        $reactionUsers[(string)$user->id] = $user->name;
                                    } elseif ($user->phone) {
                                        // Format phone number: remove domain and add + prefix
                                        $phone = preg_replace('/@.*$/', '', $user->phone);
                                        $phone = preg_match('/^\d+$/', $phone) ? '+' . $phone : $phone;
                                        $reactionUsers[(string)$user->id] = $phone;
                                    } else {
                                        $reactionUsers[(string)$user->id] = 'Unknown';
                                    }
                                }
                            }
                        }
                    }
                }
                
                $pollVotes = $pollVotesByMessageId[(int) $m->id] ?? null;
                
                // Extract filename and size from metadata
                $filename = $metadata['filename'] ?? $metadata['original_name'] ?? null;
                $fileSize = $metadata['file_size'] ?? $metadata['size'] ?? null;
                
                // Extract mimetype - fallback to metadata if media_type is null
                $mimetype = $m->media_type ?? $metadata['original_mimetype'] ?? $metadata['mimetype'] ?? null;
                
                // Load quoted message if this is a reply
                $quotedMessage = null;
                if (!empty($m->reply_to_message_id)) {
                    $quoted = DB::selectOne("
                        SELECT m.id, m.content, m.type, m.sender_id, m.chat_id,
                            CASE 
                                WHEN u.name = 'WhatsApp User' THEN c.name
                                ELSE u.name
                            END as sender_name,
                            u.phone as sender
                        FROM whatsapp_messages m
                        LEFT JOIN users u ON m.sender_id = u.id
                        LEFT JOIN chats c ON m.chat_id = c.id
                        WHERE m.id = ?
                        LIMIT 1
                    ", [$m->reply_to_message_id]);
                    
                    if ($quoted) {
                        $quotedSender = $quoted->sender ?? null;
                        if (is_string($quotedSender)) {
                            $quotedSenderLower = strtolower($quotedSender);
                            if (str_contains($quotedSenderLower, 'promise') || str_contains($quotedSenderLower, '[object')) {
                                $quotedSender = null;
                            }
                        }
                        $quotedMessage = [
                            'id' => (string) $quoted->id,
                            'content' => $quoted->content,
                            'type' => $quoted->type,
                            'sender' => $quotedSender ?? 'Unknown',
                            'sender_name' => $quoted->sender_name,
                        ];
                    }
                }
                
                // Derive better sender name if placeholder
                $rawSenderName = $m->sender_name ?? null;
                $senderName = $rawSenderName;
                if (!$senderName || strtolower($senderName) === 'whatsapp user') {
                    $senderName = $metadata['senderName']
                        ?? $metadata['sender_name']
                        ?? $metadata['name']
                        ?? $metadata['displayName']
                        ?? $metadata['pushName']
                        ?? $rawSenderName;
                }

                // Derive sender avatar
                $senderAvatarUrl = $m->sender_profile_picture_url
                    ?? ($metadata['senderProfilePictureUrl'] ?? ($metadata['sender_profile_picture_url'] ?? ($metadata['sender_avatar_url'] ?? ($metadata['profile_picture_url'] ?? null))));

                $senderPhone = $m->sender_phone ?? null;
                if (is_string($senderPhone)) {
                    $senderPhoneLower = strtolower($senderPhone);
                    if (str_contains($senderPhoneLower, 'promise') || str_contains($senderPhoneLower, '[object')) {
                        $senderPhone = null;
                    }
                }

                if (!$senderPhone && (is_string($senderName) && (strtolower($senderName) === 'whatsapp user' || strtolower($senderName) === 'temporary user'))) {
                    $senderName = 'Unknown';
                }

                return [
                    'id' => (string) $m->id,
                    'content' => $m->content,
                    'sender_id' => (string) $m->sender_id,
                    'sender_name' => $senderName,
                    'sender_phone' => $senderPhone,
                    'chat_id' => (string) $m->chat_id,
                    'created_at' => $m->created_at,
                    'updated_at' => $m->updated_at,
                    'delivered_at' => $m->delivered_at ?? null,
                    'read_at' => $m->read_at ?? null,
                    'read_by' => $readBy,
                    'type' => $m->type,
                    'direction' => $m->direction,
                    'status' => $m->status ?? 'sent',
                    'is_from_me' => ((string) $m->sender_id === (string) $currentUserId),
                    'media' => $m->media_url ?? null,
                    'mimetype' => $mimetype,
                    'filename' => $filename,
                    'size' => $fileSize,
                    'reactions' => $reactions,
                    'reaction_users' => $reactionUsers,
                    'reply_to_message_id' => $m->reply_to_message_id ?? null,
                    'quoted_message' => $quotedMessage,
                    'sender_avatar_url' => $senderAvatarUrl,
                    'poll_votes' => $pollVotes,
                    'receipt_statuses' => $receiptStatusesByMessageId[(int) $m->id] ?? [],
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

    /**
     * Save the last read message for a chat
     */
    public function saveLastRead(Request $request, $chatId)
    {
        try {
            $request->validate([
                'message_id' => 'required|string'
            ]);

            $user = $request->user() ?: User::getFirstUser();

            DB::table('chat_read_status')->updateOrInsert(
                [
                    'user_id' => $user->id,
                    'chat_id' => $chatId
                ],
                [
                    'last_read_message_id' => $request->message_id,
                    'updated_at' => now()
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Last read message saved'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to save last read message',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get the last read message for a chat
     */
    public function getLastRead(Request $request, $chatId)
    {
        try {
            $user = $request->user() ?: User::getFirstUser();

            $readStatus = DB::table('chat_read_status')
                ->where('user_id', $user->id)
                ->where('chat_id', $chatId)
                ->first();

            return response()->json([
                'success' => true,
                'last_read_message_id' => $readStatus->last_read_message_id ?? null
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to get last read message',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get members for a group chat with resolved names
     */
    public function getMembers(Request $request, $chatId)
    {
        try {
            $user = $request->user() ?: User::getFirstUser();

            // Get the chat
            $chat = Chat::findOrFail($chatId);

            if (!$chat->is_group) {
                return response()->json([
                    'error' => 'This endpoint is only for group chats'
                ], 400);
            }

            // Prefer rich participants metadata (includes community @lid participants)
            $metadataParticipants = is_array($chat->metadata) ? ($chat->metadata['participants'] ?? null) : null;
            $participants = (is_array($metadataParticipants) && count($metadataParticipants) > 0)
                ? $metadataParticipants
                : ($chat->participants ?? []);

            $members = [];

            foreach ($participants as $participant) {
                $participantJid = null;
                $isAdmin = false;

                if (is_string($participant)) {
                    $participantJid = $participant;
                } elseif (is_array($participant)) {
                    $participantJid = $participant['jid'] ?? null;
                    $isAdmin = (bool)($participant['isAdmin'] ?? false) || (bool)($participant['isSuperAdmin'] ?? false);
                }

                if (!is_string($participantJid) || trim($participantJid) === '') {
                    continue;
                }

                $participantLower = strtolower($participantJid);
                if (str_contains($participantLower, 'promise') || str_contains($participantLower, '[object')) {
                    continue;
                }

                // Skip 'me' participant
                if ($participantJid === 'me') {
                    $members[] = [
                        'id' => $user?->id,
                        'name' => 'You',
                        'phone' => 'me',
                        'avatar_url' => null,
                        'is_admin' => false,
                    ];
                    continue;
                }

                $isLid = str_ends_with($participantLower, '@lid');
                $isPhoneJid = str_ends_with($participantLower, '@s.whatsapp.net');

                if (!$isLid && !$isPhoneJid) {
                    continue;
                }

                $resolvedJid = $isPhoneJid ? $participantJid : null;
                if ($isLid) {
                    try {
                        $mapping = DB::table('chat_user')
                            ->where('whatsapp_id', $participantJid)
                            ->first();
                        if ($mapping && isset($mapping->user_id)) {
                            $mappedUser = User::find($mapping->user_id);
                            if ($mappedUser && is_string($mappedUser->phone) && trim($mappedUser->phone) !== '') {
                                $mappedPhone = trim($mappedUser->phone);
                                $resolvedJid = str_contains($mappedPhone, '@') ? $mappedPhone : ($mappedPhone . '@s.whatsapp.net');
                            }
                        }
                    } catch (\Throwable $e) {
                        // Non-fatal: if mapping lookup fails we keep phone hidden
                        $resolvedJid = null;
                    }
                }

                $lookupJid = $resolvedJid ?: $participantJid;

                // Normalize phone number - handle different formats
                $phoneNumber = preg_replace('/@.*$/', '', $lookupJid);
                $phoneNumber = preg_replace('/[^\d]/', '', $phoneNumber);

                // Look up contact from contacts table first - this is the primary source
                $appUser = User::getFirstUser();
                $contact = null;
                $avatarUrl = null;

                if ($appUser) {
                    $contact = \App\Models\Contact::where('user_id', $appUser->id)
                        ->where(function($query) use ($lookupJid, $phoneNumber) {
                            $query->where('phone', $lookupJid)
                                  ->orWhere('phone', $phoneNumber . '@s.whatsapp.net')
                                  ->orWhere('phone', '+' . $phoneNumber);
                        })
                        ->first();
                }

                $contactName = null;
                if ($contact) {
                    $contactName = $contact->name;
                    $avatarUrl = $contact->profile_picture_url;
                }

                // Fallback to chats table for name
                if (!$contactName) {
                    $contactChat = Chat::where('is_group', false)
                        ->where(function($query) use ($lookupJid, $phoneNumber) {
                            $query->whereJsonContains('participants', $lookupJid)
                                  ->orWhereJsonContains('participants', $phoneNumber)
                                  ->orWhereJsonContains('participants', '+' . $phoneNumber);
                        })
                        ->first();

                    if ($contactChat && $contactChat->name && $contactChat->name !== $participantJid) {
                        // Check if the name is not just a phone number
                        $isPhoneNumberOnly = preg_match('/^[+\d\s\-_@.]+$/', $contactChat->name);
                        if (!$isPhoneNumberOnly) {
                            $contactName = $contactChat->name;
                        }
                    }
                }

                // Only fallback to user table if we have NO contact name
                // AND the user has a real name (not "WhatsApp User")
                if (!$contactName) {
                    $userRecord = User::where('phone', $phoneNumber)
                        ->orWhere('phone', $lookupJid)
                        ->orWhere('phone', '+' . $phoneNumber)
                        ->first();

                    // Only use user name if it's not the default "WhatsApp User"
                    if ($userRecord && $userRecord->name && $userRecord->name !== 'WhatsApp User') {
                        $contactName = $userRecord->name;
                    }
                }

                // Format display name
                $displayName = $contactName;
                if (!$displayName) {
                    if ($resolvedJid && strlen($phoneNumber) >= 7) {
                        $displayName = $this->formatPhoneNumberForDisplay($resolvedJid);
                    } else {
                        $displayName = 'Unknown User';
                    }
                }

                $members[] = [
                    'id' => $participantJid,
                    'name' => $displayName,
                    'phone' => $resolvedJid,
                    'resolved_jid' => $resolvedJid,
                    'avatar_url' => $avatarUrl,
                    'is_admin' => $isAdmin,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $members
            ]);

        } catch (\Exception $e) {
            \Log::error('Error fetching group chat members: ' . $e->getMessage(), [
                'chat_id' => $chatId,
                'user_id' => $request->user()->id ?? 'unknown',
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to fetch group chat members',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Format WhatsApp JID to display phone number.
     */
    private function formatPhoneNumberForDisplay(string $jid): string
    {
        if (!str_contains($jid, '@') || str_ends_with($jid, '@s.whatsapp.net')) {
            $phoneNumber = preg_replace('/@.*$/', '', $jid);
            if (preg_match('/^\d+$/', $phoneNumber)) {
                return '+' . $phoneNumber;
            }
        }
        return $jid;
    }
}
