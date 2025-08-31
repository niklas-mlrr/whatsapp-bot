<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Pusher\Pusher;

class WebSocketController extends Controller
{
    /**
     * Authenticate the user for WebSocket access.
     */
    public function authenticate(Request $request)
    {
        $user = $request->user();
        $socketId = $request->input('socket_id');
        $channelName = $request->input('channel_name');

        if (!$user || !$socketId || !$channelName) {
            return response()->json(['error' => 'Invalid request.'], 400);
        }

        $pusher = new Pusher(
            config('broadcasting.connections.reverb.key'),
            config('broadcasting.connections.reverb.secret'),
            config('broadcasting.connections.reverb.app_id'),
            [
                'host' => config('broadcasting.connections.reverb.options.host'),
                'port' => config('broadcasting.connections.reverb.options.port'),
                'scheme' => config('broadcasting.connections.reverb.options.scheme'),
                'useTLS' => config('broadcasting.connections.reverb.options.useTLS'),
            ]
        );

        // For presence channels, include user data
        if (str_starts_with($channelName, 'presence-')) {
            $userData = [
                'id' => $user->id,
                'user_info' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar_url,
                ],
            ];
            
            $auth = $pusher->authorizeChannel($channelName, $socketId, $user->id, $userData);
        } else {
            // For private channels
            $auth = $pusher->authorizeChannel($channelName, $socketId);
        }

        return response($auth, 200, ['Content-Type' => 'application/json']);
    }

    /**
     * Handle WebSocket events (for webhooks).
     */
    public function webhook(Request $request)
    {
        // Verify the webhook signature
        $webhookSignature = $request->header('X-Pusher-Signature');
        $appSecret = config('broadcasting.connections.reverb.secret');
        $payload = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $appSecret, false);

        if ($webhookSignature !== $expectedSignature) {
            return response()->json(['error' => 'Invalid signature.'], 401);
        }

        $webhook = json_decode($payload, true);
        $events = $webhook['events'] ?? [];

        foreach ($events as $event) {
            $this->handleWebhookEvent($event);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Handle individual WebSocket events.
     */
    protected function handleWebhookEvent(array $event)
    {
        $eventName = $event['name'] ?? '';
        $channel = $event['channel'] ?? '';
        $userId = $event['user_id'] ?? null;
        $socketId = $event['socket_id'] ?? null;

        switch ($eventName) {
            case 'channel_occupied':
                // Handle user joining a channel
                $this->handleUserJoinedChannel($channel, $userId, $socketId);
                break;
                
            case 'channel_vacated':
                // Handle user leaving a channel
                $this->handleUserLeftChannel($channel, $userId, $socketId);
                break;
                
            case 'member_added':
                // Handle member added to a presence channel
                $this->handleMemberAdded($channel, $userId, $socketId, $event['user_info'] ?? []);
                break;
                
            case 'member_removed':
                // Handle member removed from a presence channel
                $this->handleMemberRemoved($channel, $userId, $socketId);
                break;
                
            // Add more event types as needed
        }
    }

    /**
     * Handle when a user joins a channel.
     */
    protected function handleUserJoinedChannel(string $channel, ?string $userId, ?string $socketId): void
    {
        // Update user's online status if this is a presence channel
        if ($userId && $user = User::find($userId)) {
            $user->update(['status' => 'online']);
            // Broadcast user online status to relevant channels
            app('websocket')->userOnline($user);
        }
    }

    /**
     * Handle when a user leaves a channel.
     */
    protected function handleUserLeftChannel(string $channel, ?string $userId, ?string $socketId): void
    {
        // Check if this was the user's last connection to any channel
        if ($userId) {
            $pusher = app('websocket')->getPusher();
            $userChannels = $pusher->get('/users/' . $userId . '/channels');
            
            // If no more channels, user is offline
            if (empty($userChannels['channels'] ?? [])) {
                if ($user = User::find($userId)) {
                    $user->update([
                        'status' => 'offline',
                        'last_seen_at' => now(),
                    ]);
                    // Broadcast user offline status to relevant channels
                    app('websocket')->userOffline($user);
                }
            }
        }
    }

    /**
     * Handle when a member is added to a presence channel.
     */
    protected function handleMemberAdded(string $channel, ?string $userId, ?string $socketId, array $userInfo): void
    {
        // Update user's online status
        if ($userId && $user = User::find($userId)) {
            $user->update(['status' => 'online']);
            // Broadcast user online status to relevant channels
            app('websocket')->userOnline($user);
        }
    }

    /**
     * Handle when a member is removed from a presence channel.
     */
    protected function handleMemberRemoved(string $channel, ?string $userId, ?string $socketId): void
    {
        // Check if this was the user's last presence channel
        if ($userId) {
            $pusher = app('websocket')->getPusher();
            $presenceChannels = $pusher->get('/users/' . $userId . '/channels', [
                'filter_by_prefix' => 'presence-'
            ]);
            
            // If no more presence channels, user is offline
            if (empty($presenceChannels['channels'] ?? [])) {
                if ($user = User::find($userId)) {
                    $user->update([
                        'status' => 'offline',
                        'last_seen_at' => now(),
                    ]);
                    // Broadcast user offline status to relevant channels
                    app('websocket')->userOffline($user);
                }
            }
        }
    }
}
