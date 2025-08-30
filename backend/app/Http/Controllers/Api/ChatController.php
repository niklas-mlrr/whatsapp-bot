<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ChatController extends Controller
{
    /**
     * Get all chats for the authenticated user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        // Process chats in chunks
        $chats = $user->chats()
            ->select(['id', 'name', 'is_group', 'avatar_url', 'updated_at'])
            ->orderBy('updated_at', 'desc')
            ->cursor()
            ->map(function ($chat) use ($user) {
                return [
                    'id' => $chat->id,
                    'name' => $chat->name,
                    'is_group' => $chat->is_group,
                    'avatar_url' => $chat->avatar_url,
                    'updated_at' => $chat->updated_at,
                    'unread_count' => $chat->unreadMessages()->count(),
                    'users' => $chat->users()
                        ->where('users.id', '!=', $user->id)
                        ->limit(5)
                        ->get(['id', 'name', 'avatar_url']),
                    'last_message' => $chat->lastMessage()->first(['id', 'content', 'created_at'])
                ];
            })
            ->paginate($request->input('per_page', 10));

        return response()->json($chats);
    }
    protected ChatService $chatService;

    public function __construct(ChatService $chatService)
    {
        $this->chatService = $chatService;
        $this->middleware('auth:api');
    }

    /**
     * Create a new direct chat between the authenticated user and another user.
     */
    public function createDirectChat(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $user = Auth::user();
        $otherUser = User::findOrFail($request->user_id);

        // Ensure users are different
        if ($user->id === $otherUser->id) {
            return response()->json([
                'message' => 'Cannot create a chat with yourself',
            ], 422);
        }

        $chat = $this->chatService->createDirectChat($user->id, $otherUser->id);

        return response()->json([
            'message' => 'Direct chat created successfully',
            'chat' => $chat->load(['users', 'lastMessage']),
        ], 201);
    }

    /**
     * Create a new group chat.
     */
    public function createGroupChat(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'participants' => 'required|array|min:1',
            'participants.*' => 'exists:users,id',
            'avatar' => 'nullable|url',
        ]);

        $user = Auth::user();
        $participantIds = array_unique(array_merge($request->participants, [$user->id]));

        $chat = $this->chatService->createGroupChat(
            $request->name,
            $participantIds,
            $user->id,
            $request->avatar
        );

        return response()->json([
            'message' => 'Group chat created successfully',
            'chat' => $chat->load(['users', 'lastMessage']),
        ], 201);
    }

    /**
     * Update chat details.
     */
    public function update(Request $request, Chat $chat)
    {
        $this->authorize('update', $chat);

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'avatar' => 'nullable|url',
        ]);

        $updates = [];
        
        if ($request->has('name')) {
            $updates['name'] = $request->name;
        }
        
        if ($request->has('avatar')) {
            $updates['metadata'] = array_merge(
                $chat->metadata ?? [],
                ['avatar' => $request->avatar]
            );
        }

        if (!empty($updates)) {
            $chat->update($updates);
        }

        return response()->json([
            'message' => 'Chat updated successfully',
            'chat' => $chat->fresh(['users', 'lastMessage']),
        ]);
    }

    /**
     * Add participants to a group chat.
     */
    public function addParticipants(Request $request, Chat $chat)
    {
        $this->authorize('update', $chat);

        $request->validate([
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'exists:users,id',
        ]);

        $chat = $this->chatService->addParticipants($chat->id, $request->user_ids);

        return response()->json([
            'message' => 'Participants added successfully',
            'chat' => $chat->load(['users', 'lastMessage']),
        ]);
    }

    /**
     * Remove participants from a group chat.
     */
    public function removeParticipants(Request $request, Chat $chat)
    {
        $this->authorize('update', $chat);

        $request->validate([
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'exists:users,id',
        ]);

        $chat = $this->chatService->removeParticipants($chat->id, $request->user_ids);

        $message = $chat->wasRecentlyDeleted 
            ? 'Chat deleted successfully' 
            : 'Participants removed successfully';

        $response = [
            'message' => $message,
            'chat' => $chat->wasRecentlyDeleted ? null : $chat->load(['users', 'lastMessage']),
        ];

        return response()->json($response, $chat->wasRecentlyDeleted ? 200 : 200);
    }

    /**
     * Leave a chat.
     */
    public function leaveChat(Chat $chat)
    {
        $user = Auth::user();
        $this->authorize('view', $chat);

        $chat = $this->chatService->removeParticipants($chat->id, [$user->id]);

        $message = $chat->wasRecentlyDeleted 
            ? 'Chat deleted successfully' 
            : 'You have left the chat';

        $response = [
            'message' => $message,
            'chat' => $chat->wasRecentlyDeleted ? null : $chat->load(['users', 'lastMessage']),
        ];

        return response()->json($response, $chat->wasRecentlyDeleted ? 200 : 200);
    }

    /**
     * Toggle mute for a chat.
     */
    public function toggleMute(Request $request, Chat $chat)
    {
        $user = Auth::user();
        $this->authorize('view', $chat);

        $request->validate([
            'mute' => 'sometimes|boolean',
            'minutes' => 'nullable|integer|min:1',
        ]);

        $mute = $request->input('mute', true);
        $minutes = $request->input('minutes');

        $this->chatService->toggleMute($chat->id, $user->id, $mute, $minutes);

        return response()->json([
            'message' => $mute ? 'Chat muted' : 'Chat unmuted',
            'muted' => $mute,
            'muted_until' => $mute ? ($minutes ? now()->addMinutes($minutes) : null) : null,
        ]);
    }

    /**
     * Mark all messages in a chat as read.
     */
    public function markAsRead(Chat $chat)
    {
        $user = Auth::user();
        $this->authorize('view', $chat);

        // Mark messages as read in the database
        $chat->users()->updateExistingPivot($user->id, [
            'read_at' => now(),
        ]);

        // Update unread count
        $chat->unread_count = 0;
        $chat->save();

        return response()->json([
            'message' => 'Chat marked as read',
            'unread_count' => 0,
        ]);
    }

    /**
     * Get messages for a chat.
     */
    public function messages(Chat $chat, Request $request)
    {
        $this->authorize('view', $chat);

        $request->validate([
            'limit' => 'sometimes|integer|min:1|max:100',
            'before' => 'sometimes|date',
        ]);

        $limit = $request->input('limit', 50);
        $before = $request->input('before');

        $query = $chat->messages()
            ->with('senderUser')
            ->orderBy('sending_time', 'desc')
            ->limit($limit);

        if ($before) {
            $query->where('sending_time', '<', $before);
        }

        $messages = $query->get();

        return response()->json([
            'messages' => $messages->sortBy('sending_time')->values(),
            'has_more' => $messages->count() >= $limit,
        ]);
    }
}
