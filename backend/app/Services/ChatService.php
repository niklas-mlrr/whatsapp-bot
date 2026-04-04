<?php

namespace App\Services;

use App\Models\Chat;
use App\Models\User;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChatService
{
    /**
     * Get all chats for a user with formatted data.
     */
    public function getChatsForUser(?User $user): array
    {
        if (!$user) {
            $user = User::getFirstUser();
        }

        $chats = $this->fetchChatsForUserId($user->id);

        // If no chats found, try fallback user
        if (count($chats) === 0) {
            $fallback = DB::select("SELECT user_id FROM chat_user GROUP BY user_id ORDER BY MIN(created_at) ASC LIMIT 1");
            if (!empty($fallback)) {
                $fallbackUserId = $fallback[0]->user_id;
                $user = User::find($fallbackUserId) ?: $user;
                $chats = $this->fetchChatsForUserId($fallbackUserId);
            }
        }

        return $this->formatChats($chats, $user);
    }

    /**
     * Fetch chats for a specific user ID.
     */
    private function fetchChatsForUserId(int $userId): array
    {
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
    }

    /**
     * Format chats for API response.
     */
    private function formatChats(array $chats, User $user): array
    {
        $formatted = [];

        // Preload contacts for all direct chats to avoid N+1 queries
        $phoneNumbers = [];
        foreach ($chats as $chat) {
            $participants = json_decode($chat->participants, true) ?? [];
            if (!$chat->is_group && !empty($participants[0])) {
                $phoneNumbers[] = $participants[0];
            }
        }

        $contactsByPhone = $this->loadContactsByPhone($user->id, $phoneNumbers);

        foreach ($chats as $chat) {
            $metadata = json_decode($chat->metadata, true) ?? [];
            $participants = json_decode($chat->participants, true) ?? [];

            $displayName = $this->formatDisplayName($chat->name, $metadata);
            $formattedParticipants = $this->formatParticipants($participants);
            $lastMessagePreview = $this->getLastMessagePreview($chat);

            // Compute avatar and contact info without model accessor queries
            $avatarUrl = $this->getAvatarUrl($chat, $metadata, $participants);
            $contactInfo = $this->getContactInfo($chat, $metadata, $participants, $contactsByPhone);

            $formatted[] = [
                'id' => $chat->id,
                'name' => $displayName,
                'original_name' => $metadata['whatsapp_id'] ?? $chat->name,
                'is_group' => $chat->is_group,
                'participants' => $formattedParticipants,
                'metadata' => $metadata,
                'avatar_url' => $avatarUrl,
                'contact_info' => $contactInfo,
                'contact_info_updated_at' => $contactInfo['updated_at'] ?? null,
                'updated_at' => $chat->updated_at,
                'created_at' => $chat->created_at,
                'description' => null,
                'is_archived' => $chat->is_archived,
                'is_muted' => $chat->is_muted,
                'pending_approval' => (bool)($chat->pending_approval ?? false),
                'unread_count' => $chat->unread_count,
                'users' => [],
                'last_message' => null,
                'last_message_preview' => $lastMessagePreview,
            ];
        }

        return $formatted;
    }

    /**
     * Load contacts by phone numbers to avoid N+1 queries.
     */
    private function loadContactsByPhone(int $userId, array $phoneNumbers): array
    {
        if (empty($phoneNumbers)) {
            return [];
        }

        $contacts = \App\Models\Contact::where('user_id', $userId)
            ->whereIn('phone', $phoneNumbers)
            ->get();

        $byPhone = [];
        foreach ($contacts as $contact) {
            $byPhone[$contact->phone] = $contact;
        }

        return $byPhone;
    }

    /**
     * Get avatar URL for a chat without triggering model accessors.
     */
    private function getAvatarUrl($chat, array $metadata, array $participants): ?string
    {
        if ($chat->is_group) {
            return $metadata['avatar_url'] ?? null;
        }

        // For direct chats, avatar comes from contact
        $phoneNumber = $participants[0] ?? null;
        if (!$phoneNumber) {
            return null;
        }

        // Avatar will be set from contact_info
        return null;
    }

    /**
     * Get contact info without triggering model accessors.
     */
    private function getContactInfo($chat, array $metadata, array $participants, array $contactsByPhone): array
    {
        if ($chat->is_group) {
            return [
                'profile_picture_url' => $metadata['profile_picture_url'] ?? null,
                'description' => $metadata['description'] ?? null,
                'type' => 'group',
            ];
        }

        $phoneNumber = $participants[0] ?? $metadata['whatsapp_id'] ?? null;
        if (!$phoneNumber) {
            return [
                'profile_picture_url' => null,
                'description' => null,
                'type' => 'unknown',
            ];
        }

        $contact = $contactsByPhone[$phoneNumber] ?? null;
        if ($contact) {
            return [
                'profile_picture_url' => $contact->profile_picture_url,
                'description' => $contact->bio,
                'type' => 'contact',
                'updated_at' => $contact->updated_at?->toIso8601String(),
            ];
        }

        return [
            'profile_picture_url' => null,
            'description' => null,
            'type' => 'unknown',
        ];
    }

    /**
     * Format display name for a chat.
     */
    private function formatDisplayName(string $name, array $metadata): string
    {
        if (isset($metadata['whatsapp_id']) && $name === $metadata['whatsapp_id']) {
            return $this->formatPhoneNumberForDisplay($name);
        }
        return $name;
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

    /**
     * Format and clean participants list.
     */
    private function formatParticipants(array $participants): array
    {
        $clean = [];
        foreach ($participants as $participant) {
            if (!is_string($participant)) {
                continue;
            }

            $participantLower = strtolower($participant);
            if (str_contains($participantLower, 'promise') || str_contains($participantLower, '[object')) {
                continue;
            }

            if ($participant === 'me') {
                $clean[] = 'me';
            } else {
                $clean[] = preg_replace('/@.*$/', '', $participant);
            }
        }
        return $clean;
    }

    /**
     * Get last message preview for pending chats.
     */
    private function getLastMessagePreview($chat): ?string
    {
        if (!$chat->pending_approval) {
            return null;
        }

        $lastMsg = DB::selectOne("
            SELECT content, type, created_at
            FROM whatsapp_messages
            WHERE chat_id = ?
            ORDER BY created_at DESC
            LIMIT 1
        ", [$chat->id]);

        if (!$lastMsg) {
            return null;
        }

        $preview = $lastMsg->content;
        if ($lastMsg->type !== 'text') {
            $preview = ucfirst($lastMsg->type);
        }

        return strlen($preview) > 50 ? substr($preview, 0, 50) . '...' : $preview;
    }

    /**
     * Create a new direct chat between two users.
     */
    public function createDirectChat(string $user1Id, string $user2Id): Chat
    {
        $user1 = User::findOrFail($user1Id);
        $user2 = User::findOrFail($user2Id);
        
        // Check if a direct chat already exists between these users
        $existingChat = $this->findDirectChat($user1Id, $user2Id);
        
        if ($existingChat) {
            return $existingChat;
        }
        
        // Create a new chat
        $chat = new Chat([
            'name' => "Chat between {$user1->name} and {$user2->name}",
            'is_group' => false,
            'participants' => [$user1->phone, $user2->phone],
            'metadata' => [
                'type' => 'direct',
                'created_by' => $user1->id,
            ],
        ]);
        
        return DB::transaction(function () use ($chat, $user1, $user2) {
            $chat->save();
            
            // Attach users to the chat
            $chat->users()->attach([
                $user1->id => ['role' => 'admin'],
                $user2->id => ['role' => 'admin'],
            ]);
            
            return $chat->load('users');
        });
    }
    
    /**
     * Create a new group chat.
     */
    public function createGroupChat(string $name, array $participantIds, ?string $createdById = null, ?string $avatar = null): Chat
    {
        $creator = $createdById ? User::findOrFail($createdById) : null;
        $participants = User::whereIn('id', $participantIds)->get();
        
        if ($participants->count() < 2) {
            throw new \InvalidArgumentException('A group chat must have at least 2 participants');
        }
        
        $chat = new Chat([
            'name' => $name,
            'is_group' => true,
            'participants' => $participants->pluck('phone')->toArray(),
            'metadata' => [
                'type' => 'group',
                'created_by' => $creator ? $creator->id : null,
                'avatar' => $avatar,
            ],
        ]);
        
        return DB::transaction(function () use ($chat, $participants, $creator) {
            $chat->save();
            
            // Attach participants
            $participantData = [];
            foreach ($participants as $participant) {
                $participantData[$participant->id] = [
                    'role' => $creator && $participant->id === $creator->id ? 'admin' : 'member',
                    'muted_until' => null,
                ];
            }
            
            $chat->users()->attach($participantData);
            
            return $chat->load('users');
        });
    }
    
    /**
     * Find an existing direct chat between two users.
     */
    public function findDirectChat(string $user1Id, string $user2Id): ?Chat
    {
        $user1 = User::findOrFail($user1Id);
        $user2 = User::findOrFail($user2Id);
        
        return Chat::where('is_group', false)
            ->whereJsonContains('participants', $user1->phone)
            ->whereJsonContains('participants', $user2->phone)
            ->first();
    }
    
    /**
     * Add participants to a group chat.
     */
    public function addParticipants(string $chatId, array $userIds): Chat
    {
        $chat = Chat::findOrFail($chatId);
        
        if (!$chat->is_group) {
            throw new \InvalidArgumentException('Cannot add participants to a direct chat');
        }
        
        $users = User::whereIn('id', $userIds)->get();
        
        return DB::transaction(function () use ($chat, $users) {
            // Update participants list
            $currentParticipants = collect($chat->participants);
            $newParticipants = $users->pluck('phone');
            $combined = $currentParticipants->concat($newParticipants)->unique()->values()->toArray();
            
            $chat->update(['participants' => $combined]);
            
            // Add users to the chat
            $chat->users()->syncWithoutDetaching(
                $users->pluck('id')->mapWithKeys(fn ($id) => [$id => ['role' => 'member']])
            );
            
            return $chat->load('users');
        });
    }
    
    /**
     * Remove participants from a group chat.
     */
    public function removeParticipants(string $chatId, array $userIds): Chat
    {
        $chat = Chat::findOrFail($chatId);
        
        if (!$chat->is_group) {
            throw new \InvalidArgumentException('Cannot remove participants from a direct chat');
        }
        
        $users = User::whereIn('id', $userIds)->get();
        
        return DB::transaction(function () use ($chat, $users) {
            // Update participants list
            $currentParticipants = collect($chat->participants);
            $removePhones = $users->pluck('phone');
            $remaining = $currentParticipants->reject(fn ($phone) => $removePhones->contains($phone))->values()->toArray();
            
            $chat->update(['participants' => $remaining]);
            
            // Remove users from the chat
            $chat->users()->detach($users->pluck('id'));
            
            // If no participants left, delete the chat
            if (count($remaining) === 0) {
                $chat->delete();
                return $chat;
            }
            
            // If the last admin left, assign admin to another participant
            $hasAdmin = $chat->users()->wherePivot('role', 'admin')->exists();
            if (!$hasAdmin && $chat->users()->exists()) {
                $newAdmin = $chat->users()->first();
                $chat->users()->updateExistingPivot($newAdmin->id, ['role' => 'admin']);
            }
            
            return $chat->load('users');
        });
    }
    
    /**
     * Update chat details.
     */
    public function updateChat(string $chatId, array $data): Chat
    {
        $chat = Chat::findOrFail($chatId);
        
        $updates = [];
        
        if (isset($data['name'])) {
            $updates['name'] = $data['name'];
        }
        
        if (isset($data['avatar'])) {
            $updates['metadata'] = array_merge(
                $chat->metadata ?? [],
                ['avatar' => $data['avatar']]
            );
        }
        
        if (!empty($updates)) {
            $chat->update($updates);
        }
        
        return $chat->fresh();
    }
    
    /**
     * Mark messages as read for a user in a chat.
     */
    public function markAsRead(string $chatId, string $userId): void
    {
        $chat = Chat::findOrFail($chatId);
        $user = User::findOrFail($userId);
        
        // Update the read_at timestamp in the pivot table
        $chat->users()->updateExistingPivot($user->id, [
            'read_at' => now(),
        ]);
        
        // Decrement unread count if needed
        if ($chat->unread_count > 0) {
            $chat->decrement('unread_count');
        }
    }
    
    /**
     * Get unread messages count for a user in all chats.
     */
    public function getUnreadCounts(string $userId): array
    {
        $user = User::with(['chats' => function ($query) {
            $query->select('chats.id', 'chats.name', 'chats.last_message_at');
        }])->findOrFail($userId);
        
        return $user->chats->mapWithKeys(function ($chat) use ($user) {
            $unreadCount = $chat->pivot->read_at < $chat->last_message_at 
                ? $chat->unread_count 
                : 0;
                
            return [$chat->id => $unreadCount];
        })->toArray();
    }
    
    /**
     * Mute or unmute a chat for a user.
     */
    public function toggleMute(string $chatId, string $userId, bool $mute = true, ?int $minutes = null): void
    {
        $chat = Chat::findOrFail($chatId);
        $user = User::findOrFail($userId);
        
        $mutedUntil = $mute 
            ? ($minutes ? now()->addMinutes($minutes) : now()->addYears(100)) // 100 years is effectively forever
            : null;
            
        $chat->users()->updateExistingPivot($user->id, [
            'muted_until' => $mutedUntil,
        ]);
    }
}
