<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LidMapping;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LidMappingController extends Controller
{
    /**
     * Store a LID-to-phone mapping.
     * Called by the receiver when it discovers a LID-to-phone relationship.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'lid_jid' => 'required|string|max:100',
            'phone_jid' => 'required|string|max:100',
            'display_name' => 'nullable|string|max:255',
        ]);

        // Validate JID formats
        if (!str_ends_with(strtolower($validated['lid_jid']), '@lid')) {
            return response()->json([
                'error' => 'Invalid LID format. Must end with @lid',
            ], 400);
        }

        if (!str_ends_with(strtolower($validated['phone_jid']), '@s.whatsapp.net')) {
            return response()->json([
                'error' => 'Invalid phone JID format. Must end with @s.whatsapp.net',
            ], 400);
        }

        try {
            $mapping = LidMapping::updateOrCreate(
                ['lid_jid' => $validated['lid_jid']],
                [
                    'phone_jid' => $validated['phone_jid'],
                    'display_name' => $validated['display_name'] ?? null,
                    'last_seen_at' => now(),
                ]
            );

            Log::debug('Stored LID mapping', [
                'lid_jid' => $validated['lid_jid'],
                'phone_jid' => $validated['phone_jid'],
                'display_name' => $validated['display_name'] ?? null,
            ]);

            return response()->json([
                'success' => true,
                'mapping' => $mapping,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to store LID mapping', [
                'error' => $e->getMessage(),
                'lid_jid' => $validated['lid_jid'],
            ]);

            return response()->json([
                'error' => 'Failed to store LID mapping',
            ], 500);
        }
    }

    /**
     * Bulk store LID-to-phone mappings.
     * Called by the receiver to batch upload multiple mappings.
     */
    public function bulkStore(Request $request)
    {
        $validated = $request->validate([
            'mappings' => 'required|array',
            'mappings.*.lid_jid' => 'required|string|max:100',
            'mappings.*.phone_jid' => 'required|string|max:100',
            'mappings.*.display_name' => 'nullable|string|max:255',
        ]);

        $stored = 0;
        $errors = [];

        foreach ($validated['mappings'] as $mapping) {
            try {
                // Validate JID formats
                if (!str_ends_with(strtolower($mapping['lid_jid']), '@lid')) {
                    $errors[] = ['lid_jid' => $mapping['lid_jid'], 'error' => 'Invalid LID format'];
                    continue;
                }

                if (!str_ends_with(strtolower($mapping['phone_jid']), '@s.whatsapp.net')) {
                    $errors[] = ['lid_jid' => $mapping['lid_jid'], 'error' => 'Invalid phone JID format'];
                    continue;
                }

                LidMapping::updateOrCreate(
                    ['lid_jid' => $mapping['lid_jid']],
                    [
                        'phone_jid' => $mapping['phone_jid'],
                        'display_name' => $mapping['display_name'] ?? null,
                        'last_seen_at' => now(),
                    ]
                );
                $stored++;
            } catch (\Exception $e) {
                $errors[] = ['lid_jid' => $mapping['lid_jid'], 'error' => $e->getMessage()];
            }
        }

        return response()->json([
            'success' => true,
            'stored' => $stored,
            'errors' => $errors,
        ]);
    }
}
