<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppContactSyncController extends Controller
{
    public function sync(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'contacts' => 'required|array',
                'contacts.*.phone' => 'required|string|max:100',
                'contacts.*.name' => 'nullable|string|max:255',
                'contacts.*.profile_picture_url' => 'nullable|string|max:2048',
                'contacts.*.bio' => 'nullable|string|max:500',
            ]);

            $user = User::getFirstUser();
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No user found'
                ], 500);
            }

            $created = 0;
            $updated = 0;
            $skipped = 0;

            foreach ($validated['contacts'] as $c) {
                $phone = Contact::normalizePhone($c['phone']);
                $name = isset($c['name']) && is_string($c['name']) ? trim($c['name']) : '';
                $profilePictureUrl = $c['profile_picture_url'] ?? null;
                $bio = $c['bio'] ?? null;

                $existing = Contact::where('user_id', $user->id)
                    ->where('phone', $phone)
                    ->first();

                if ($existing) {
                    $updates = [];

                    if ($name !== '' && ($existing->name === null || $existing->name === '' || str_starts_with($existing->name, 'Unbekannter Benutzer'))) {
                        $updates['name'] = $name;
                    }

                    if (is_string($profilePictureUrl) && $profilePictureUrl !== '' && $existing->profile_picture_url !== $profilePictureUrl) {
                        $updates['profile_picture_url'] = $profilePictureUrl;
                    }

                    if (is_string($bio) && $bio !== '' && $existing->bio !== $bio) {
                        $updates['bio'] = $bio;
                    }

                    if (!empty($updates)) {
                        $existing->update($updates);
                        $updated++;
                    } else {
                        $skipped++;
                    }

                    continue;
                }

                $createData = [
                    'user_id' => $user->id,
                    'phone' => $phone,
                    'name' => $name !== '' ? $name : 'Unbekannter Benutzer',
                ];
                if (is_string($profilePictureUrl) && $profilePictureUrl !== '') {
                    $createData['profile_picture_url'] = $profilePictureUrl;
                }
                if (is_string($bio) && $bio !== '') {
                    $createData['bio'] = $bio;
                }

                Contact::create($createData);
                $created++;
            }

            Log::channel('whatsapp')->info('Contacts synced from receiver', [
                'total' => count($validated['contacts']),
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
            ]);

            return response()->json([
                'status' => 'ok',
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->error('Failed to sync contacts from receiver', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to sync contacts',
            ], 500);
        }
    }
}
