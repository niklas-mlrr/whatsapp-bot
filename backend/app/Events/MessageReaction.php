<?php

namespace App\Events;

use App\Models\User;
use App\Models\WhatsAppMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageReaction implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The message instance.
     *
     * @var \App\Models\WhatsAppMessage
     */
    public $message;

    /**
     * The user who reacted.
     *
     * @var \App\Models\User
     */
    public $user;

    /**
     * The reaction emoji.
     *
     * @var string
     */
    public $reaction;

    /**
     * Whether the reaction was added or removed.
     *
     * @var bool
     */
    public $added;

    /**
     * Create a new event instance.
     *
     * @param  \App\Models\WhatsAppMessage  $message
     * @param  \App\Models\User  $user
     * @param  string  $reaction
     * @param  bool  $added
     * @return void
     */
    public function __construct(WhatsAppMessage $message, User $user, string $reaction, bool $added = true)
    {
        $this->message = $message;
        $this->user = $user->only(['id', 'name', 'avatar_url']);
        $this->reaction = $reaction;
        $this->added = $added;
        
        // Don't include the message content in the broadcast
        $this->message->makeHidden(['content', 'media_url', 'media_type']);
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        // Broadcast to the chat channel and the user's private channel
        return [
            new PrivateChannel('chat.' . $this->message->chat_id),
            new PrivateChannel('user.' . $this->message->sender_phone),
        ];
    }
    
    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs()
    {
        return 'message.reaction';
    }
    
    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith()
    {
        return [
            'message_id' => $this->message->id,
            'chat_id' => $this->message->chat_id,
            'user' => $this->user,
            'reaction' => $this->reaction,
            'added' => $this->added,
            'timestamp' => now()->toIso8601String(),
        ];
    }
    
    /**
     * Determine if this event should broadcast.
     *
     * @return bool
     */
    public function broadcastWhen()
    {
        // Only broadcast if the message has a chat
        return (bool) $this->message->chat;
    }
}
