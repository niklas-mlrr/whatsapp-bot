<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PollVote extends Model
{
    protected $fillable = [
        'message_id',
        'user_id',
        'option_index',
        'voted_at',
    ];

    protected $casts = [
        'voted_at' => 'datetime',
    ];

    /**
     * Get the message that this vote belongs to
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(WhatsAppMessage::class, 'message_id');
    }

    /**
     * Get the user who cast this vote
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
