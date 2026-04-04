<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Chat;
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
        'profile_picture_url',
        'bio',
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

    /**
     * Get the user's profile picture URL.
     * Prioritizes profile_picture_url (from WhatsApp) over avatar.
     */
    public function getProfilePictureUrlAttribute()
    {
        // Check if profile_picture_url exists and has a value (WhatsApp profile picture)
        if (isset($this->attributes['profile_picture_url']) && $this->attributes['profile_picture_url']) {
            return $this->attributes['profile_picture_url'];
        }
        
        // Fall back to avatar_url
        return $this->avatar_url;
    }

    protected $appends = [
        'avatar_url',
        'profile_picture_url',
    ];

    /**
     * The chats that the user belongs to.
     */
    public function chats(): BelongsToMany
    {
        return $this->belongsToMany(Chat::class, 'chat_user', 'user_id', 'chat_id')
            ->withTimestamps()
            ->withPivot(['role', 'joined_at', 'left_at']);
    }

    /**
     * The chats created by the user.
     */
    public function createdChats(): HasMany
    {
        return $this->hasMany(Chat::class, 'created_by');
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
        // Password must be set via INITIAL_ADMIN_PASSWORD env variable
        // If not set, generate a random password and log it
        if (!$user) {
            $adminPassword = env('INITIAL_ADMIN_PASSWORD');

            if (empty($adminPassword)) {
                // Generate a secure random password if none provided
                $adminPassword = Str::random(24);
                \Illuminate\Support\Facades\Log::info('Initial admin user created', [
                    'password' => $adminPassword,
                    'note' => 'Store this password securely or set INITIAL_ADMIN_PASSWORD in .env'
                ]);
            }

            $user = static::create([
                'name' => 'Admin',
                'password' => Hash::make($adminPassword),
                'phone' => '+0000000000',
            ]);
        }

        return $user;
    }
}
