<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class WhatsAppMessageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $mediaUrl = null;
        
        // Generate media URL based on storage type
        if ($this->media) {
            if (filter_var($this->media, FILTER_VALIDATE_URL)) {
                $mediaUrl = $this->media; // Already a full URL
            } else if (Storage::disk('public')->exists($this->media)) {
                $mediaUrl = asset('storage/' . $this->media);
            } else if (Storage::disk('s3')->exists($this->media)) {
                $mediaUrl = Storage::disk('s3')->url($this->media);
            }
        }
        
        // Generate thumbnail URL if available
        $thumbnailUrl = null;
        if (!empty($this->metadata['thumbnail_path'])) {
            $thumbnailPath = $this->metadata['thumbnail_path'];
            if (filter_var($thumbnailPath, FILTER_VALIDATE_URL)) {
                $thumbnailUrl = $thumbnailPath;
            } else if (Storage::disk('public')->exists($thumbnailPath)) {
                $thumbnailUrl = asset('storage/' . $thumbnailPath);
            } else if (Storage::disk('s3')->exists($thumbnailPath)) {
                $thumbnailUrl = Storage::disk('s3')->url($thumbnailPath);
            }
        }
        
        // Extract media metadata
        $mediaMetadata = [];
        if (!empty($this->metadata['media_metadata'])) {
            $mediaMetadata = $this->metadata['media_metadata'];
        }
        
        // Determine if the message is from the current user
        $isFromCurrentUser = $request->user() && $this->sender_id === $request->user()->id;
        
        return [
            // Core message data
            'id' => $this->id,
            'sender_id' => $this->sender_id,
            'sender' => $this->sender,
            'chat_id' => $this->chat_id,
            'chat' => $this->chat,
            'type' => $this->type,
            'content' => $this->content,
            'mimetype' => $this->mimetype,
            'sending_time' => $this->sending_time?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            
            // Message status
            'direction' => $this->direction,
            'status' => $this->status,
            'read_at' => $this->read_at?->toIso8601String(),
            'is_read' => (bool) $this->read_at,
            'is_from_me' => $isFromCurrentUser,
            
            // Media information
            'media' => $this->when($this->media, [
                'path' => $this->media,
                'url' => $mediaUrl,
                'thumbnail_url' => $thumbnailUrl,
                'metadata' => $mediaMetadata,
            ]),
            
            // Reactions and metadata
            'reactions' => $this->reactions ?? [],
            'metadata' => $this->metadata ?? [],
            
            // Relationships
            'sender_info' => $this->whenLoaded('senderUser', function () {
                return [
                    'id' => $this->senderUser->id,
                    'name' => $this->senderUser->name,
                    'avatar' => $this->senderUser->avatar_url,
                    'phone' => $this->senderUser->phone,
                ];
            }),
        ];
    }

    /**
     * Get any additional data that should be returned with the resource array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function with($request)
    {
        return [
            'meta' => [
                'version' => '1.0.0',
                'api_version' => '1.0',
                'timestamp' => now()->toIso8601String(),
            ],
        ];
    }
}
