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
            Broadcast::event('chat.' . $message->chat, 'message-status-updated', [
                'message_id' => $message->id,
                'status' => $message->status,
                'read_at' => $message->read_at?->toIso8601String(),
                'event' => 'message-status-updated',
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
            Broadcast::event('chat.' . $message->chat, 'message-reaction-updated', [
                'message_id' => $message->id,
                'user_id' => $userId,
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
}
