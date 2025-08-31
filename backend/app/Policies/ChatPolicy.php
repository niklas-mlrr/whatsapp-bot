<?php

namespace App\Policies;

use App\Models\Chat;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ChatPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true; // Any authenticated user can view their chats
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Chat $chat): bool
    {
        // User can view the chat if they are a participant
        return $chat->users()->where('users.id', $user->id)->exists();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true; // Any authenticated user can create chats
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Chat $chat): bool
    {
        // Only group admins or the chat creator can update the chat
        if ($chat->is_group) {
            $pivot = $chat->users()->find($user->id)?->pivot;
            return $pivot && $pivot->role === 'admin';
        }
        
        // For direct chats, both participants can update their own info
        return $chat->participants->contains($user->phone);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Chat $chat): bool
    {
        // Only group admins or the chat creator can delete the chat
        if ($chat->is_group) {
            $pivot = $chat->users()->find($user->id)?->pivot;
            return $pivot && $pivot->role === 'admin';
        }
        
        // For direct chats, users can only leave, not delete
        return false;
    }

    /**
     * Determine whether the user can add participants to the chat.
     */
    public function addParticipants(User $user, Chat $chat): bool
    {
        // Only group admins can add participants
        if ($chat->is_group) {
            $pivot = $chat->users()->find($user->id)?->pivot;
            return $pivot && $pivot->role === 'admin';
        }
        
        // Can't add participants to direct chats
        return false;
    }

    /**
     * Determine whether the user can remove participants from the chat.
     */
    public function removeParticipants(User $user, Chat $chat, User $targetUser = null): bool
    {
        // Can't remove participants from direct chats
        if (!$chat->is_group) {
            return false;
        }
        
        $userPivot = $chat->users()->find($user->id)?->pivot;
        
        // User must be an admin to remove participants
        if (!$userPivot || $userPivot->role !== 'admin') {
            return false;
        }
        
        // If removing another admin, the user must be the chat creator
        if ($targetUser) {
            $targetPivot = $chat->users()->find($targetUser->id)?->pivot;
            if ($targetPivot && $targetPivot->role === 'admin') {
                return $user->id === $chat->created_by;
            }
        }
        
        return true;
    }

    /**
     * Determine whether the user can leave the chat.
     */
    public function leave(User $user, Chat $chat): bool
    {
        // Can't leave direct chats (must delete them instead)
        if (!$chat->is_group) {
            return false;
        }
        
        // Can't leave if you're the last admin
        if ($this->isLastAdmin($user, $chat)) {
            return false;
        }
        
        return $chat->participants->contains($user->phone);
    }
    
    /**
     * Check if the user is the last admin in the chat.
     */
    protected function isLastAdmin(User $user, Chat $chat): bool
    {
        $userPivot = $chat->users()->find($user->id)?->pivot;
        
        // If the user is not an admin, they can't be the last admin
        if (!$userPivot || $userPivot->role !== 'admin') {
            return false;
        }
        
        // Count how many other admins there are
        $otherAdmins = $chat->users()
            ->where('users.id', '!=', $user->id)
            ->wherePivot('role', 'admin')
            ->count();
            
        return $otherAdmins === 0;
    }
}
