<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use App\Models\Chat;

class WhatsAppMessage extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'sender_id',
        'chat_id',
        'type',
        'status',
        'content',
        'media_url',
        'media_type',
        'media_size',
        'read_at',
        'metadata',
        'reactions',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'metadata' => 'array',
        'reactions' => 'array',
        'is_read' => 'boolean',
    ];
    
    protected $appends = [
        'is_read', 
        'sender_name',
        'sender_avatar',
        'sending_time',
        'sender',
        'chat',
        'direction',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (isset($model->metadata['message_id'])) {
                $existing = WhatsAppMessage::where('metadata->message_id', $model->metadata['message_id'])->first();
                if ($existing) {
                    throw new \Exception('Duplicate message detected');
                }
            }
        });
    }

    /**
     * The users who have read this message.
     */
    public function readers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'message_reads')
            ->withTimestamps()
            ->withPivot('read_at');
    }

    /**
     * The user who sent this message.
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * Get the sender's name.
     */
    public function getSenderNameAttribute(): ?string
    {
        if ($this->sender) {
            return $this->sender->name;
        }
        return $this->sender; // Fallback to the sender field if no user relationship
    }

    /**
     * Get the sender's avatar URL.
     */
    public function getSenderAvatarAttribute(): ?string
    {
        if ($this->sender) {
            return $this->sender->avatar_url;
        }
        return null;
    }
    
    // Default values for attributes
    protected $attributes = [
        'metadata' => '{}',
        'status' => 'sent',
    ];

    // Scopes
    public function scopeIncoming(Builder $query): Builder
    {
        return $query->where('status', 'sent');
    }

    public function scopeOutgoing(Builder $query): Builder
    {
        return $query->where('status', 'delivered');
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }
    
    public function scopeForChat(Builder $query, string $chatId): Builder
    {
        return $query->where('chat_id', $chatId);
    }
    
    public function scopeRecent(Builder $query, int $days = 30): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days))
                    ->orderBy('created_at', 'desc');
    }
    
    public function scopeWithMedia(Builder $query): Builder
    {
        return $query->whereNotNull('media_url');
    }
    
    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }
    
    public function scopeSearch(Builder $query, string $searchTerm): Builder
    {
        return $query->where(function($q) use ($searchTerm) {
            $q->where('content', 'like', "%{$searchTerm}%")
              ->orWhereHas('sender', function($subQ) use ($searchTerm) {
                  $subQ->where('name', 'like', "%{$searchTerm}%");
              });
        });
    }

    // Relationships
    public function senderUser()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
    
    /**
     * Get the chat this message belongs to.
     */
    public function chatRelation(): BelongsTo
    {
        return $this->belongsTo(Chat::class, 'chat_id');
    }

    // Accessors & Mutators
    public function getIsReadAttribute(): bool
    {
        return !is_null($this->read_at);
    }
    
    public function getMediaUrlAttribute(): ?string
    {
        // Check if media_url column exists and has a value
        if (isset($this->attributes['media_url']) && $this->attributes['media_url']) {
            $mediaUrl = $this->attributes['media_url'];
        } elseif (isset($this->attributes['media']) && $this->attributes['media']) {
            $mediaUrl = $this->attributes['media'];
        } else {
            return null;
        }
        
        if (filter_var($mediaUrl, FILTER_VALIDATE_URL)) {
            return $mediaUrl;
        }
        
        if (Storage::disk('public')->exists($mediaUrl)) {
            return Storage::disk('public')->url($mediaUrl);
        }
        
        if (config('filesystems.default') === 's3' && Storage::disk('s3')->exists($mediaUrl)) {
            return Storage::disk('s3')->url($mediaUrl);
        }
        
        return null;
    }
    
    public function getThumbnailUrlAttribute(): ?string
    {
        $thumbnailPath = $this->metadata['thumbnail_path'] ?? null;
        
        if (!$thumbnailPath) {
            return null;
        }
        
        if (filter_var($thumbnailPath, FILTER_VALIDATE_URL)) {
            return $thumbnailPath;
        }
        
        if (Storage::disk('public')->exists($thumbnailPath)) {
            return Storage::disk('public')->url($thumbnailPath);
        }
        
        if (config('filesystems.default') === 's3' && Storage::disk('s3')->exists($thumbnailPath)) {
            return Storage::disk('s3')->url($thumbnailPath);
        }
        
        return null;
    }
    
    public function getMediaMetadataAttribute(): array
    {
        return $this->metadata['media_metadata'] ?? [];
    }
    
    /**
     * Get the sending time (from metadata or created_at).
     */
    public function getSendingTimeAttribute()
    {
        if (isset($this->metadata['sending_time'])) {
            return \Carbon\Carbon::parse($this->metadata['sending_time']);
        }
        return $this->created_at;
    }
    
    /**
     * Get the sender identifier (phone number from metadata).
     */
    public function getSenderAttribute(): ?string
    {
        return $this->metadata['sender'] ?? $this->senderUser?->phone ?? null;
    }
    
    /**
     * Get the chat identifier (WhatsApp JID from metadata).
     */
    public function getChatAttribute(): ?string
    {
        return $this->metadata['chat'] ?? $this->chatRelation?->metadata['whatsapp_id'] ?? null;
    }
    
    /**
     * Get the message direction (incoming/outgoing).
     */
    public function getDirectionAttribute(): string
    {
        // If the sender is the authenticated user, it's outgoing
        if ($this->senderUser && auth()->check() && $this->sender_id === auth()->id()) {
            return 'outgoing';
        }
        return 'incoming';
    }
    
    // Helper Methods
    public function markAsRead(): bool
    {
        if ($this->is_read) {
            return true;
        }
        
        return $this->update([
            'read_at' => now(),
            'status' => 'read',
        ]);
    }
    

    
    public function updateStatus(string $status, ?string $error = null): bool
    {
        $updateData = ['status' => $status];
        
        if ($status === 'read' && !$this->read_at) {
            $updateData['read_at'] = now();
        }
        
        if ($status === 'failed' && $error) {
            $metadata = $this->metadata ?? [];
            $metadata['error'] = $error;
            $updateData['metadata'] = $metadata;
        }
        
        return $this->update($updateData);
    }
    
    public function getMediaType(): string
    {
        if (!$this->media_type) {
            return 'unknown';
        }
        
        return $this->media_type;
    }
}