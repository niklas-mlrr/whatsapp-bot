<?php

namespace App\Services;

use App\Models\Chat;
use App\Models\User;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Log;
use Pusher\Pusher;
use Pusher\PusherException;

class EnhancedWebSocketService
{
    protected Pusher $pusher;

    public function __construct()
    {
        $this->pusher = new Pusher(
            config('broadcasting.connections.pusher.key'),
            config('broadcasting.connections.pusher.secret'),
            config('broadcasting.connections.pusher.app_id'),
            [
                'host' => config('broadcasting.connections.pusher.options.host'),
                'port' => config('broadcasting.connections.pusher.options.port'),
                'scheme' => config('broadcasting.connections.pusher.options.scheme'),
                'useTLS' => config('broadcasting.connections.pusher.options.useTLS'),
                'encrypted' => config('broadcasting.connections.pusher.options.encrypted'),
                'cluster' => config('broadcasting.connections.pusher.options.cluster'),
            ]
        );
    }

    // Message related methods
    public function newMessage(WhatsAppMessage $message): void
    {
        $this->triggerToChat(
            $message->chat,
            'message.sent',
            [
                'message' => new \App\Http\Resources\WhatsAppMessageResource($message),
                'chat_id' => $message->chat,
            ]
        );
    }

    public function messageStatusUpdated(WhatsAppMessage $message): void
    {
        $this->triggerToChat(
            $message->chat,
            'message.status_updated',
            [
                'message_id' => $message->id,
                'status' => $message->status,
                'read_at' => $message->read_at?->toIso8601String(),
                'chat_id' => $message->chat,
            ]
        );
    }

    public function messageReactionUpdated(WhatsAppMessage $message, string $userId, ?string $reaction): void
    {
        $this->triggerToChat(
            $message->chat,
            'message.reaction_updated',
            [
                'message_id' => $message->id,
                'user_id' => $userId,
                'reaction' => $reaction,
                'reactions' => $message->reactions ?? [],
                'chat_id' => $message->chat,
            ]
        );
    }

    // Chat related methods
    public function chatCreated(Chat $chat, ?User $createdBy = null): void
    {
        $this->triggerToChat(
            $chat->id,
            'chat.created',
            [
                'chat' => $chat->only(['id', 'name', 'is_group', 'is_archived', 'is_muted', 'unread_count', 'last_message_at', 'created_at', 'updated_at']),
                'created_by' => $createdBy ? $createdBy->only(['id', 'name', 'avatar_url']) : null,
            ]
        );
    }

    public function chatUpdated(Chat $chat, ?User $updatedBy = null, array $changes = []): void
    {
        $this->triggerToChat(
            $chat->id,
            'chat.updated',
            [
                'chat' => $chat->only(['id', 'name', 'is_group', 'is_archived', 'is_muted', 'unread_count', 'last_message_at', 'created_at', 'updated_at']),
                'updated_by' => $updatedBy ? $updatedBy->only(['id', 'name', 'avatar_url']) : null,
                'changes' => $changes,
            ]
        );
    }

    public function chatDeleted(Chat $chat, ?User $deletedBy = null): void
    {
        $this->triggerToChat(
            $chat->id,
            'chat.deleted',
            [
                'chat_id' => $chat->id,
                'deleted_by' => $deletedBy ? $deletedBy->only(['id', 'name']) : null,
            ]
        );
    }

    // User presence methods
    public function userOnline(User $user): void
    {
        $this->triggerToUser(
            $user,
            'user.online',
            [
                'user_id' => $user->id,
                'name' => $user->name,
                'last_seen_at' => $user->last_seen_at?->toIso8601String(),
            ]
        );
    }

    public function userOffline(User $user): void
    {
        $this->triggerToUser(
            $user,
            'user.offline',
            [
                'user_id' => $user->id,
                'name' => $user->name,
                'last_seen_at' => $user->last_seen_at?->toIso8601String(),
            ]
        );
    }

    public function userTyping(string $chatId, User $user, bool $isTyping = true): void
    {
        $this->triggerToChat(
            $chatId,
            'user.typing',
            [
                'user_id' => $user->id,
                'name' => $user->name,
                'is_typing' => $isTyping,
                'chat_id' => $chatId,
            ],
            'private-'.$user->id // Exclude the user who is typing
        );
    }

    // Notification methods
    public function newNotification(User $user, array $notification): void
    {
        $this->triggerToUser(
            $user,
            'notification.new',
            array_merge($notification, [
                'user_id' => $user->id,
                'timestamp' => now()->toIso8601String(),
            ])
        );
    }

    // Helper methods
    protected function triggerToChat(string $chatId, string $event, array $data, ?string $excludeSocketId = null): void
    {
        try {
            $this->pusher->trigger(
                'private-chat.'.$chatId,
                $event,
                $data,
                $excludeSocketId
            );
        } catch (PusherException $e) {
            Log::error('WebSocket error triggering chat event', [
                'event' => $event,
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function triggerToUser(User $user, string $event, array $data, ?string $excludeSocketId = null): void
    {
        try {
            $this->pusher->trigger(
                'private-user.'.$user->id,
                $event,
                $data,
                $excludeSocketId
            );
        } catch (PusherException $e) {
            Log::error('WebSocket error triggering user event', [
                'event' => $event,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // Presence channel methods
    public function getPresenceUsers(string $channelName): array
    {
        try {
            $result = $this->pusher->get('/channels/presence-'.$channelName.'/users');
            return $result['users'] ?? [];
        } catch (PusherException $e) {
            Log::error('Failed to get presence users', [
                'channel' => $channelName,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    public function isUserOnline(User $user, string $channelName): bool
    {
        $users = $this->getPresenceUsers($channelName);
        return in_array($user->id, array_column($users, 'id'));
    }
}
