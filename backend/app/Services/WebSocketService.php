<?php

namespace App\Services;

use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Broadcast;

class WebSocketService
{
    public function __construct()
    {
        // No initialization needed as we'll use the Broadcast facade
    }

    public function newMessage(WhatsAppMessage $message): void
    {
        try {
            Broadcast::event('chat.' . $message->chat_id, 'message.sent', [
                'message' => new \App\Http\Resources\WhatsAppMessageResource($message),
                'event' => 'message.sent',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send WebSocket notification', [
                'error' => $e->getMessage(),
                'message_id' => $message->id,
            ]);
        }
    }

    public function messageStatusUpdated(WhatsAppMessage $message): void
    {
        try {
            Broadcast::event('chat.' . $message->chat_id, 'message-status-updated', [
                'message_id' => $message->id,
                'status' => $message->status,
                'read_at' => $message->read_at?->toIso8601String(),
                'is_read' => (bool) $message->read_at,
                'event' => 'message-status-updated',
            ]);
            
            Log::info('Message status update broadcast sent', [
                'message_id' => $message->id,
                'chat_id' => $message->chat_id,
                'status' => $message->status,
                'read_at' => $message->read_at?->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send message status update', [
                'error' => $e->getMessage(),
                'message_id' => $message->id,
            ]);
        }
    }

    public function messageReactionUpdated(WhatsAppMessage $message, string $userId, ?string $reaction): void
    {
        try {
            // Fetch user information for the reaction
            $user = \App\Models\User::find($userId);
            $userName = null;
            if ($user) {
                // Prefer name if it's not a placeholder, otherwise use formatted phone
                if ($user->name && strtolower($user->name) !== 'whatsapp user') {
                    $userName = $user->name;
                } elseif ($user->phone) {
                    // Format phone number: remove domain and add + prefix
                    $phone = preg_replace('/@.*$/', '', $user->phone);
                    $userName = preg_match('/^\d+$/', $phone) ? '+' . $phone : $phone;
                }
            }
            
            Broadcast::event('chat.' . $message->chat_id, 'message-reaction-updated', [
                'message_id' => $message->id,
                'user_id' => $userId,
                'user_name' => $userName,
                'reaction' => $reaction,
                'event' => 'message-reaction-updated',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send reaction update', [
                'error' => $e->getMessage(),
                'message_id' => $message->id,
            ]);
        }
    }

    public function newChatCreated(\App\Models\Chat $chat): void
    {
        try {
            // Broadcast to a global channel that all users listen to
            Broadcast::event('chats', 'chat.created', [
                'chat' => [
                    'id' => $chat->id,
                    'name' => $chat->name,
                    'pending_approval' => $chat->pending_approval,
                    'is_group' => $chat->is_group,
                    'created_at' => $chat->created_at,
                ],
                'event' => 'chat.created',
            ]);
            
            Log::info('New chat broadcast sent', [
                'chat_id' => $chat->id,
                'pending_approval' => $chat->pending_approval
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send new chat notification', [
                'error' => $e->getMessage(),
                'chat_id' => $chat->id,
            ]);
        }
    }
}
