<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use App\Models\Chat;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
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
        'last_seen_at' => 'datetime',
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
        // Check if avatar_url column exists and has a value
        if (isset($this->attributes['avatar_url']) && $this->attributes['avatar_url']) {
            return $this->attributes['avatar_url'];
        }
        
        // Check if avatar column exists and has a value
        if (isset($this->attributes['avatar']) && $this->attributes['avatar']) {
            return $this->attributes['avatar'];
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
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
    }

    /**
     * Mark the user as offline.
     */
    public function markAsOffline()
    {
        $this->update([
            'status' => 'offline',
            'last_seen_at' => now(),
        ]);
    }

    /**
     * Check if the user is online.
     */
    public function isOnline(): bool
    {
        if ($this->status === 'online') {
            return true;
        }

        // Consider user online if they were active in the last 5 minutes
        return $this->last_seen_at && $this->last_seen_at->diffInMinutes(now()) < 5;
    }

    /**
     * Get the first user or create one if none exists
     * Prioritizes admin users (users with non-WhatsApp names) over WhatsApp users
     *
     * @return \App\Models\User
     */
    public static function getFirstUser()
    {
        // First, try to find a user named "Admin" (the main admin user)
        $adminUser = static::where('name', 'Admin')->first();
        if ($adminUser) {
            return $adminUser;
        }
        
        // If no "Admin" user exists, try to find a user with a non-WhatsApp name
        $namedUser = static::where('name', '!=', 'WhatsApp User')->first();
        if ($namedUser) {
            return $namedUser;
        }
        
        // Fall back to the first user in the database
        $user = static::first();
        
        // If no user exists at all, create a default admin user
        if (!$user) {
            $user = static::create([
                'name' => 'Admin',
                'password' => Hash::make('admin123'),
            ]);
        }
        
        return $user;
    }
}
