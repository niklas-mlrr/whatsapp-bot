<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LidMapping extends Model
{
    protected $fillable = [
        'lid_jid',
        'phone_jid',
        'display_name',
        'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    /**
     * Find a phone JID for a given LID.
     */
    public static function findPhoneByLid(string $lidJid): ?string
    {
        $mapping = static::where('lid_jid', $lidJid)->first();
        return $mapping?->phone_jid;
    }

    /**
     * Store or update a LID-to-phone mapping.
     */
    public static function storeMapping(string $lidJid, string $phoneJid, ?string $displayName = null): void
    {
        static::updateOrCreate(
            ['lid_jid' => $lidJid],
            [
                'phone_jid' => $phoneJid,
                'display_name' => $displayName,
                'last_seen_at' => now(),
            ]
        );
    }
}
