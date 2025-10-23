<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;

class WhatsAppGroupController extends Controller
{
    public function createOrUpdate(Request $request): JsonResponse
    {
        try {
            Log::channel('whatsapp')->debug('Group metadata request received', [
                'body' => $request->all(),
                'participants_count' => count($request->input('participants', [])),
            ]);

            $validated = $request->validate([
                'group_id' => 'required|string',
                'name' => 'required|string',
                'description' => 'nullable|string',
                'participants' => 'nullable|array',
                'participants.*.jid' => 'nullable|string',
                'participants.*.isAdmin' => 'nullable|boolean',
                'participants.*.isSuperAdmin' => 'nullable|boolean',
                'profile_picture_url' => 'nullable|string',
                'created_at' => 'nullable|string',
            ]);

            Log::channel('whatsapp')->info('Group metadata received', [
                'group_id' => $validated['group_id'],
                'name' => $validated['name'],
                'participant_count' => count($validated['participants'] ?? []),
            ]);

            $group = Chat::firstOrCreate(
                [
                    'is_group' => true,
                    'metadata->whatsapp_id' => $validated['group_id'],
                ],
                [
                    'name' => $validated['name'],
                    'is_group' => true,
                    'pending_approval' => true,
                    'participants' => array_map(fn($p) => $p['jid'], $validated['participants'] ?? []),
                    'metadata' => [
                        'whatsapp_id' => $validated['group_id'],
                        'description' => $validated['description'] ?? '',
                        'created_at' => $validated['created_at'],
                        'participants' => $validated['participants'] ?? [],
                        'profile_picture_url' => $validated['profile_picture_url'] ?? null,
                    ],
                ]
            );

            if (!$group->wasRecentlyCreated) {
                $metadata = $group->metadata ?? [];
                $metadata['description'] = $validated['description'] ?? ($metadata['description'] ?? '');
                $metadata['created_at'] = $validated['created_at'] ?? ($metadata['created_at'] ?? null);
                $metadata['participants'] = $validated['participants'] ?? ($metadata['participants'] ?? []);
                $metadata['profile_picture_url'] = $validated['profile_picture_url'] ?? ($metadata['profile_picture_url'] ?? null);

                $group->update([
                    'name' => $validated['name'],
                    'participants' => array_map(fn($p) => $p['jid'], $validated['participants'] ?? []),
                    'metadata' => $metadata,
                ]);
                Log::channel('whatsapp')->info('Group updated', ['group_id' => $group->id]);
            } else {
                Log::channel('whatsapp')->info('Group created', ['group_id' => $group->id]);
            }

            $operator = User::getFirstUser();
            if ($operator && !$group->users()->where('chat_user.user_id', $operator->id)->exists()) {
                $group->users()->attach($operator->id);
                Log::channel('whatsapp')->info('Attached operator to group', [
                    'group_id' => $group->id,
                    'operator_id' => $operator->id,
                ]);
            }

            return response()->json([
                'status' => 'ok',
                'message' => 'Group created or updated',
                'group_id' => $group->id,
                'whatsapp_id' => $validated['group_id'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::channel('whatsapp')->error('Validation error in group metadata', [
                'errors' => $e->errors(),
                'request_body' => $request->all(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error creating/updating group', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_body' => $request->all(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create or update group',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
