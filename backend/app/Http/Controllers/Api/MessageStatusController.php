<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppMessage;
use App\Services\WebSocketService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Models\User;

class MessageStatusController extends Controller
{
    protected WebSocketService $webSocketService;

    public function __construct(WebSocketService $webSocketService)
    {
        $this->webSocketService = $webSocketService;
    }

    /**
     * Mark a message as read
     */
    public function markAsRead(string $messageId): JsonResponse
    {
        try {
            $message = WhatsAppMessage::findOrFail($messageId);

            // Only update if not already read
            if (!$message->read_at) {
                $message->update([
                    'read_at' => now(),
                    'status' => 'read',
                ]);

                // Notify via WebSocket
                $this->webSocketService->messageStatusUpdated($message);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Message marked as read',
                'data' => [
                    'message_id' => $message->id,
                    'read_at' => $message->read_at->toIso8601String(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error marking message as read', [
                'error' => $e->getMessage(),
                'message_id' => $messageId,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to mark message as read',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mark multiple messages as read
     */
    public function markMultipleAsRead(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'message_ids' => 'required|array',
            'message_ids.*' => 'required|string|exists:whatsapp_messages,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $messageIds = $request->input('message_ids');
            // Use the single app user instead of auth()->user()
            $currentUser = User::getFirstUser();

            if (!$currentUser) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized',
                ], 401);
            }

            $updatedMessages = [];
            $now = now();

            // Update all messages in a single query for better performance
            $messages = WhatsAppMessage::whereIn('id', $messageIds)
                ->whereNull('read_at')
                ->get();

            foreach ($messages as $message) {
                $message->update([
                    'read_at' => $now,
                    'status' => 'read',
                ]);

                // Add current user to read_by array if it exists
                if ($message->read_by) {
                    $readBy = is_array($message->read_by) ? $message->read_by : [];
                    if (!in_array($currentUser->id, $readBy)) {
                        $readBy[] = $currentUser->id;
                        $message->update(['read_by' => $readBy]);
                    }
                } else {
                    $message->update(['read_by' => [$currentUser->id]]);
                }

                $updatedMessages[] = $message->id;

                // Notify via WebSocket
                $this->webSocketService->messageStatusUpdated($message);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Messages marked as read',
                'data' => [
                    'updated_count' => count($updatedMessages),
                    'message_ids' => $updatedMessages,
                ],
            ]);

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error marking multiple messages as read', [
                'error' => $e->getMessage(),
                'message_ids' => $request->input('message_ids'),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to mark messages as read',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update message status (sent, delivered, read, failed)
     */
    public function updateStatus(string $messageId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:sent,delivered,read,failed',
            'error' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $message = WhatsAppMessage::findOrFail($messageId);
            $status = $request->input('status');

            $updateData = ['status' => $status];

            // Set read_at timestamp if status is 'read' and not already set
            if ($status === 'read' && !$message->read_at) {
                $updateData['read_at'] = now();
            }

            // Add error message if provided and status is failed
            if ($status === 'failed' && $request->has('error')) {
                $updateData['metadata'] = array_merge(
                    $message->metadata ?? [],
                    ['error' => $request->input('error')]
                );
            }

            $message->update($updateData);

            // Notify via WebSocket
            $this->webSocketService->messageStatusUpdated($message);

            return response()->json([
                'status' => 'success',
                'message' => 'Message status updated',
                'data' => [
                    'message_id' => $message->id,
                    'status' => $message->status,
                    'read_at' => $message->read_at?->toIso8601String(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error updating message status', [
                'error' => $e->getMessage(),
                'message_id' => $messageId,
                'status' => $request->input('status'),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update message status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add or update a reaction to a message
     */
    public function addReaction(string $messageId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|string|max:255',
            'reaction' => 'required|string|max:10', // Emoji reactions are usually 1-4 chars
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $message = WhatsAppMessage::findOrFail($messageId);
            $userId = $request->input('user_id');
            $reaction = $request->input('reaction');

            // Get current reactions
            $reactions = $message->reactions ?? [];

            // If the same user already has a reaction, update it, otherwise add it
            $reactions[$userId] = $reaction;

            // Update the message with the new reactions
            $message->update(['reactions' => $reactions]);

            // Notify via WebSocket
            $this->webSocketService->messageReactionUpdated($message, $userId, $reaction);

            return response()->json([
                'status' => 'success',
                'message' => 'Reaction added',
                'data' => [
                    'message_id' => $message->id,
                    'reactions' => $reactions,
                ],
            ]);

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error adding reaction', [
                'error' => $e->getMessage(),
                'message_id' => $messageId,
                'user_id' => $request->input('user_id'),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to add reaction',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove a reaction from a message
     */
    public function removeReaction(string $messageId, string $userId): JsonResponse
    {
        try {
            $message = WhatsAppMessage::findOrFail($messageId);
            $reactions = $message->reactions ?? [];

            // Check if the user has a reaction to remove
            if (!isset($reactions[$userId])) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'No reaction to remove',
                    'data' => [
                        'message_id' => $message->id,
                        'reactions' => $reactions,
                    ],
                ]);
            }

            // Remove the reaction
            unset($reactions[$userId]);

            // If there are no more reactions, set to null to save space
            $message->update([
                'reactions' => !empty($reactions) ? $reactions : null,
            ]);

            // Notify via WebSocket (null reaction indicates removal)
            $this->webSocketService->messageReactionUpdated($message, $userId, null);

            return response()->json([
                'status' => 'success',
                'message' => 'Reaction removed',
                'data' => [
                    'message_id' => $message->id,
                    'reactions' => $reactions,
                ],
            ]);

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error removing reaction', [
                'error' => $e->getMessage(),
                'message_id' => $messageId,
                'user_id' => $userId,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to remove reaction',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
