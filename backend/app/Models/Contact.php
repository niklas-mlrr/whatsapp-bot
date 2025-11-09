<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Contact extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'contacts';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'name',
        'phone',
        'profile_picture_url',
        'bio',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = [
        'has_chat',
        'chat_id',
    ];

    /**
     * The user who owns this contact.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the chat associated with this contact (if any).
     */
    public function chat()
    {
        // Find a direct chat where this contact's phone is a participant
        return Chat::where('is_group', false)
            ->get()
            ->first(function($chat) {
                $phoneNumber = preg_replace('/@.*$/', '', $this->phone);
                $metadata = is_string($chat->metadata) ? json_decode($chat->metadata, true) : $chat->metadata;
                
                if (!$metadata || !isset($metadata['whatsapp_id'])) {
                    return false;
                }
                
                $storedPhone = preg_replace('/@.*$/', '', $metadata['whatsapp_id']);
                return $storedPhone === $phoneNumber;
            });
    }

    /**
     * Check if this contact has an associated chat.
     */
    public function getHasChatAttribute(): bool
    {
        return $this->chat() !== null;
    }

    /**
     * Get the chat ID if it exists.
     */
    public function getChatIdAttribute(): ?int
    {
        $chat = $this->chat();
        return $chat ? $chat->id : null;
    }

    /**
     * Normalize phone number to WhatsApp JID format.
     */
    public static function normalizePhone(string $phone): string
    {
        // Remove any existing @ suffix
        $phone = preg_replace('/@.*$/', '', $phone);
        
        // Remove + and any non-digit characters
        $phone = preg_replace('/[^\d]/', '', $phone);
        
        // Add WhatsApp suffix if not present
        if (!str_contains($phone, '@')) {
            $phone .= '@s.whatsapp.net';
        }
        
        return $phone;
    }

    /**
     * Get display phone number (formatted for UI).
     */
    public function getDisplayPhoneAttribute(): string
    {
        $phone = preg_replace('/@.*$/', '', $this->phone);
        return $phone && ctype_digit($phone) ? '+' . $phone : $phone;
    }

    /**
     * Scope to search contacts by name or phone.
     */
    public function scopeSearch($query, string $searchTerm)
    {
        return $query->where(function($q) use ($searchTerm) {
            $q->where('name', 'like', "%{$searchTerm}%")
              ->orWhere('phone', 'like', "%{$searchTerm}%");
        });
    }

    /**
     * Scope to get contacts for a specific user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
