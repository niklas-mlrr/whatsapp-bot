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
            // For read receipts, we need to identify who read the message
            // In a single-user setup, this would be the app user
            // For multi-user, we'd need to track which user is associated with the WhatsApp number
            $readerUserId = null;
            
            // Get the chat to find participants - use chatRelation() method
            $chat = $message->chatRelation;
            if ($chat) {
                // For direct messages, the reader is the other participant (not the sender)
                if (!$chat->is_group) {
                    // Find the user who is not the sender
                    $participants = $chat->users;
                    $reader = $participants->firstWhere('id', '!=', $message->sender_id);
                    if ($reader) {
                        $readerUserId = $reader->id;
                    }
                } else {
                    // For group messages, we'd need to track who read it
                    // For now, we'll send null for group messages as the read tracking is more complex
                    $readerUserId = null;
                }
            }
            
            $broadcastData = [
                'message_id' => $message->id,
                'status' => $message->status,
                'read_at' => $message->read_at?->toIso8601String(),
                'is_read' => (bool) $message->read_at,
                'user_id' => $readerUserId, // Add user_id for frontend compatibility
                'event' => 'message-status-updated',
            ];
            
            Log::channel('whatsapp')->info('Broadcasting message status update', [
                'channel' => 'chat.' . $message->chat_id,
                'event' => 'message-status-updated',
                'message_id' => $message->id,
                'chat_id' => $message->chat_id,
                'status' => $message->status,
                'read_at' => $message->read_at?->toIso8601String(),
                'reader_user_id' => $readerUserId,
                'broadcast_data' => $broadcastData,
            ]);
            
            Broadcast::event('chat.' . $message->chat_id, 'message-status-updated', $broadcastData);
            
            Log::channel('whatsapp')->info('Message status update broadcast completed', [
                'message_id' => $message->id,
                'chat_id' => $message->chat_id,
                'status' => $message->status,
            ]);
        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Failed to send message status update', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'message_id' => $message->id,
                'chat_id' => $message->chat_id ?? null,
            ]);
        }
    }

    public function messageUpdated(WhatsAppMessage $message): void
    {
        try {
            // For poll messages, use the specific poll update event
            if ($message->type === 'poll') {
                Broadcast::event('chat.' . $message->chat_id, 'message.poll_updated', [
                    'message_id' => $message->id,
                    'chat_id' => $message->chat_id,
                    'poll_votes' => $message->pollVotes->map(function ($vote) {
                        return [
                            'user_id' => $vote->user_id,
                            'option_index' => $vote->option_index,
                            'voted_at' => $vote->voted_at?->toIso8601String(),
                        ];
                    }),
                    'metadata' => $message->metadata,
                ]);
                
                Log::info('Poll update broadcast sent', [
                    'message_id' => $message->id,
                    'chat_id' => $message->chat_id,
                    'event' => 'message.poll_updated',
                ]);
            } else {
                // For other message types, use the general message update event
                Broadcast::event('chat.' . $message->chat_id, 'message-updated', [
                    'message' => new \App\Http\Resources\WhatsAppMessageResource($message),
                    'event' => 'message-updated',
                ]);
                
                Log::info('Message update broadcast sent', [
                    'message_id' => $message->id,
                    'chat_id' => $message->chat_id,
                    'event' => 'message-updated',
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send message update', [
                'error' => $e->getMessage(),
                'message_id' => $message->id,
                'chat_id' => $message->chat_id,
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

    public function contactUpdated(\App\Models\Contact $contact): void
    {
        try {
            // Broadcast to a global contacts channel
            Broadcast::event('contacts', 'contact.updated', [
                'contact' => [
                    'id' => $contact->id,
                    'phone' => $contact->phone,
                    'name' => $contact->name,
                    'profile_picture_url' => $contact->profile_picture_url,
                    'bio' => $contact->bio,
                    'updated_at' => $contact->updated_at?->toIso8601String(),
                ],
                'event' => 'contact.updated',
            ]);
            
            Log::channel('whatsapp')->info('Contact update broadcast sent', [
                'contact_id' => $contact->id,
                'phone' => $contact->phone,
                'has_profile_picture' => !empty($contact->profile_picture_url),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send contact update notification', [
                'error' => $e->getMessage(),
                'contact_id' => $contact->id,
            ]);
        }
    }
}
