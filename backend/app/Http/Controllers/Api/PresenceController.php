<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PresenceController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api');
    }


    /**
     * Set the typing status for the authenticated user in a chat.
     */
    public function setTyping(Chat $chat, Request $request)
    {
        $request->validate([
            'is_typing' => 'sometimes|boolean',
        ]);

        $user = Auth::user();
        $isTyping = $request->input('is_typing', true);

        // Verify the user is a participant in the chat
        if (!$chat->participants->contains($user->phone)) {
            return response()->json([
                'message' => 'You are not a participant in this chat',
            ], 403);
        }

        // Broadcast the typing status to other chat participants
        broadcast(new UserTyping($chat, $user, $isTyping))->toOthers();

        return response()->json([
            'typing' => $isTyping,
            'user_id' => $user->id,
            'chat_id' => $chat->id,
        ]);
    }

}
