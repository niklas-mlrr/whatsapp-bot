<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\Hash;
// use App\Models\Chat;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'avatar',
        'status',
        'last_seen_at',
        'settings',
    ];
    


    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'last_seen_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'password' => 'hashed',
        'email_verified_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'is_online' => 'boolean',
    ];

    protected $appends = [
        'avatar_url',
    ];

    /**
     * The chats that the user belongs to.
     */
    public function chats()
    {
        // Temporarily disabled to avoid circular dependency issues
        return null;
    }

    /**
     * The chats created by the user.
     */
    public function createdChats()
    {
        // Temporarily disabled to avoid circular dependency issues
        return null;
    }

    /**
     * Get the user's avatar URL.
     */
    public function getAvatarUrlAttribute()
    {
        if ($this->attributes['avatar_url']) {
            return $this->attributes['avatar_url'];
        }
        
        // Generate a default avatar URL based on the user's name
        $name = urlencode(trim($this->name));
        return "https://ui-avatars.com/api/?name={$name}&background=random&color=fff";
    }

    /**
     * Mark the user as online.
     */
    public function markAsOnline()
    {
        $this->update([
            'is_online' => true,
            'last_seen_at' => now(),
        ]);
    }

    /**
     * Mark the user as offline.
     */
    public function markAsOffline()
    {
        $this->update([
            'is_online' => false,
            'last_seen_at' => now(),
        ]);
    }

    /**
     * Check if the user is online.
     */
    public function isOnline(): bool
    {
        if ($this->is_online) {
            return true;
        }

        // Consider user online if they were active in the last 5 minutes
        return $this->last_seen_at && $this->last_seen_at->diffInMinutes(now()) < 5;
    }

    /**
     * Get the first user or create one if none exists
     *
     * @return \App\Models\User
     */
    public static function getFirstUser()
    {
        // First try to get any existing user
        $user = static::first();
        
        // If no user exists, create a default admin user
        if (!$user) {
            $user = static::create([
                'name' => 'Admin',
                'password' => Hash::make('admin123'),
            ]);
        }
        
        return $user;
    }
}
