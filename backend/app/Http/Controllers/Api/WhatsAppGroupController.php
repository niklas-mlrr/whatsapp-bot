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
    /**
     * Normalize a WhatsApp JID: accept only real phone JIDs for participants list.
     * Returns the JID if it's a phone JID (..@s.whatsapp.net). Returns null otherwise.
     */
    private function normalizeJid(?string $jid): ?string
    {
        if (!$jid) return $jid;
        $jid = strtolower(trim($jid));
        if (preg_match('/^\+?\d{5,}@s\.whatsapp\.net$/', $jid)) {
            return $jid;
        }
        return null;
    }

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

            // Participants for storage: only phone JIDs
            $participantJids = collect($validated['participants'] ?? [])
                ->map(fn($p) => $this->normalizeJid($p['jid'] ?? null))
                ->filter()
                ->values()
                ->all();

            $group = Chat::firstOrCreate(
                [
                    'is_group' => true,
                    'metadata->whatsapp_id' => $validated['group_id'],
                ],
                [
                    'name' => $validated['name'],
                    'is_group' => true,
                    'pending_approval' => false, // We have full metadata, no need for approval
                    'participants' => $participantJids,
                    'metadata' => [
                        'whatsapp_id' => $validated['group_id'],
                        'description' => $validated['description'] ?? '',
                        'created_at' => $validated['created_at'],
                        'participants' => $validated['participants'] ?? [],
                        'profile_picture_url' => $validated['profile_picture_url'] ?? null,
                    ],
                    'contact_profile_picture_url' => $validated['profile_picture_url'] ?? null,
                    'contact_info_updated_at' => !empty($validated['profile_picture_url']) ? now() : null,
                ]
            );

            if ($group->wasRecentlyCreated) {
                Log::channel('whatsapp')->info('Group created', [
                    'group_id' => $group->id,
                    'whatsapp_id' => $validated['group_id'],
                    'name' => $validated['name'],
                    'participant_count' => count($participantJids),
                    'is_new' => true
                ]);
            } else {
                // Update existing group
                $metadata = $group->metadata ?? [];
                $metadata['description'] = $validated['description'] ?? ($metadata['description'] ?? '');
                $metadata['created_at'] = $validated['created_at'] ?? ($metadata['created_at'] ?? null);
                if (array_key_exists('participants', $validated) && !empty($validated['participants'])) {
                    $metadata['participants'] = $validated['participants'];
                }
                $metadata['profile_picture_url'] = $validated['profile_picture_url'] ?? ($metadata['profile_picture_url'] ?? null);
                $updates = [
                    'name' => $validated['name'],
                    'pending_approval' => false, // We have full metadata now, approve the group
                    // Only update participants list when we received some valid phone JIDs; otherwise keep existing
                    // 'participants' => $participantJids,
                    'metadata' => $metadata,
                ];
                if (!empty($participantJids)) {
                    $updates['participants'] = $participantJids;
                }
                // If profile picture changed, update direct field and bump timestamp
                if (array_key_exists('profile_picture_url', $validated) && !empty($validated['profile_picture_url'])) {
                    if ($group->contact_profile_picture_url !== $validated['profile_picture_url']) {
                        $updates['contact_profile_picture_url'] = $validated['profile_picture_url'];
                        $updates['contact_info_updated_at'] = now();
                    }
                }

                $group->update($updates);
                Log::channel('whatsapp')->info('Group updated', [
                    'group_id' => $group->id,
                    'whatsapp_id' => $validated['group_id'],
                    'name' => $validated['name'],
                    'existing_name' => $group->name,
                    'participant_count' => count($participantJids),
                    'is_new' => false
                ]);
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
