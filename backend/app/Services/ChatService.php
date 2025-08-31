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
