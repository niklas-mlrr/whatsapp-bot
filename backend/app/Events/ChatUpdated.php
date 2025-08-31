<?php

namespace App\Events;

use App\Models\Chat;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The chat instance.
     *
     * @var \App\Models\Chat
     */
    public $chat;

    /**
     * The user that triggered the event.
     *
     * @var \App\Models\User|null
     */
    public $user;

    /**
     * The event type (created, updated, deleted, etc.).
     *
     * @var string
     */
    public $eventType;

    /**
     * Additional data to be sent with the event.
     *
     * @var array
     */
    public $data;

    /**
     * Create a new event instance.
     *
     * @param  \App\Models\Chat  $chat
     * @param  string  $eventType
     * @param  array  $data
     * @param  \App\Models\User|null  $user
     * @return void
     */
    public function __construct(Chat $chat, string $eventType = 'updated', array $data = [], ?User $user = null)
    {
        $this->chat = $chat;
        $this->eventType = $eventType;
        $this->data = $data;
        $this->user = $user;
        
        // Don't expose sensitive data
        unset($this->chat->pivot);
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        // Notify all participants of the chat
        $channels = [];
        
        foreach ($this->chat->participants as $participantPhone) {
            $channels[] = new PrivateChannel('user.' . $participantPhone);
        }
        
        // Also broadcast to a channel for the chat itself
        $channels[] = new PrivateChannel('chat.' . $this->chat->id);
        
        return $channels;
    }
    
    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs()
    {
        return 'chat.' . $this->eventType;
    }
    
    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith()
    {
        return [
            'chat' => array_merge(
                $this->chat->only(['id', 'name', 'is_group', 'is_archived', 'is_muted', 'unread_count', 'last_message_at', 'created_at', 'updated_at']),
                ['event_type' => $this->eventType]
            ),
            'user' => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'avatar' => $this->user->avatar_url,
            ] : null,
            'data' => $this->data,
            'timestamp' => now()->toIso8601String(),
        ];
    }
    
    /**
     * Determine if this event should be broadcast.
     *
     * @return bool
     */
    public function broadcastWhen()
    {
        // Only broadcast if the chat has participants
        return !empty($this->chat->participants);
    }
}
