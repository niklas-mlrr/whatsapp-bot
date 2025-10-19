<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    public function __construct()
    {
        // Middleware is applied in routes/api.php
    }

    /**
     * Format WhatsApp JID to display phone number
     * Converts "4917646765869@s.whatsapp.net" to "+4917646765869"
     */
    private function formatPhoneNumberForDisplay(string $jid): string
    {
        // Extract phone number from JID (remove @s.whatsapp.net or similar)
        $phoneNumber = preg_replace('/@.*$/', '', $jid);
        
        // Add + prefix if it's a phone number (contains only digits)
        if (preg_match('/^\d+$/', $phoneNumber)) {
            return '+' . $phoneNumber;
        }
        
        // Return as-is if it's not a phone number format
        return $jid;
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
                    c.pending_approval,
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
                
                // Format display name for phone numbers
                $displayName = $chat->name;
                $metadata = json_decode($chat->metadata, true) ?? [];
                
                // If this chat has a whatsapp_id in metadata and the name equals the whatsapp_id,
                // it means it's an auto-generated name from the JID, so format it nicely
                if (isset($metadata['whatsapp_id']) && $chat->name === $metadata['whatsapp_id']) {
                    $displayName = $this->formatPhoneNumberForDisplay($chat->name);
                }
                
                // Parse participants array and clean it for display
                $participants = json_decode($chat->participants, true) ?? [];
                $cleanParticipants = [];
                foreach ($participants as $participant) {
                    if ($participant === 'me') {
                        $cleanParticipants[] = 'me';
                    } else {
                        // Remove @s.whatsapp.net suffix for cleaner display
                        $cleanParticipants[] = preg_replace('/@.*$/', '', $participant);
                    }
                }
                
                // Get last message preview for pending chats
                $lastMessagePreview = null;
                if ($chat->pending_approval) {
                    $lastMsg = DB::selectOne("
                        SELECT content, type, created_at 
                        FROM whatsapp_messages 
                        WHERE chat_id = ? 
                        ORDER BY created_at DESC 
                        LIMIT 1
                    ", [$chat->id]);
                    
                    if ($lastMsg) {
                        $preview = $lastMsg->content;
                        if ($lastMsg->type !== 'text') {
                            $preview = ucfirst($lastMsg->type); // e.g., "Image", "Video", "Document"
                        }
                        // Truncate to 50 characters
                        if (strlen($preview) > 50) {
                            $preview = substr($preview, 0, 50) . '...';
                        }
                        $lastMessagePreview = $preview;
                    }
                }
                
                $formattedChats[] = [
                    'id' => $chat->id,
                    'name' => $displayName, // Use formatted display name
                    'original_name' => isset($metadata['whatsapp_id']) ? $metadata['whatsapp_id'] : $chat->name, // Full JID for technical operations
                    'is_group' => $chat->is_group,
                    'participants' => $cleanParticipants, // Clean phone numbers without @s.whatsapp.net
                    'metadata' => $metadata,
                    'avatar_url' => $chatModel->avatar_url,
                    'updated_at' => $chat->updated_at,
                    'created_at' => $chat->created_at,
                    'description' => null, // Description field doesn't exist in database
                    'is_archived' => $chat->is_archived,
                    'is_muted' => $chat->is_muted,
                    'pending_approval' => (bool)($chat->pending_approval ?? false),
                    'unread_count' => $chat->unread_count,
                    'users' => [],
                    'last_message' => null,
                    'last_message_preview' => $lastMessagePreview
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
                    if (preg_match('/^(\d+)@/', $displayName, $matches)) {
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
                            m.reactions
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
                            m.media_type,
                            m.metadata,
                            m.reactions
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
                        m.media_url,
                        m.media_type,
                        m.metadata,
                        m.reactions
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
            // Decode metadata if it's a JSON string
            $metadata = isset($m->metadata) && is_string($m->metadata) ? json_decode($m->metadata, true) : [];
            if (!is_array($metadata)) {
                $metadata = [];
            }
            
            // Decode reactions if it's a JSON string
            $reactions = null;
            if (isset($m->reactions)) {
                $reactions = is_string($m->reactions) ? json_decode($m->reactions, true) : $m->reactions;
                if (!is_array($reactions)) {
                    $reactions = null;
                }
            }
            
            // Extract filename and size from metadata
            $filename = $metadata['filename'] ?? $metadata['original_name'] ?? null;
            $fileSize = $metadata['file_size'] ?? $metadata['size'] ?? null;
            
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
                'filename' => $filename,
                'size' => $fileSize,
                'reactions' => $reactions,
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
                            m.metadata,
                            m.reactions
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
                        m.metadata,
                        m.reactions
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
                
                // Decode reactions if it's a JSON string
                $reactions = null;
                if (isset($m->reactions)) {
                    $reactions = is_string($m->reactions) ? json_decode($m->reactions, true) : $m->reactions;
                    if (!is_array($reactions)) {
                        $reactions = null;
                    }
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
                    'reactions' => $reactions,
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
