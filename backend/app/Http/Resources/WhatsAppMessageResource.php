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
        $mediaPath = $this->media
            ?? $this->media_url
            ?? ($this->metadata['media_path'] ?? null);

        $mediaUrl = null;

        if ($mediaPath) {
            if (filter_var($mediaPath, FILTER_VALIDATE_URL)) {
                $mediaUrl = $mediaPath;
            } elseif (Storage::disk('public')->exists($mediaPath)) {
                $mediaUrl = asset('storage/' . $mediaPath);
            } elseif (Storage::disk('s3')->exists($mediaPath)) {
                $mediaUrl = Storage::disk('s3')->url($mediaPath);
            }
        }
        
        // Ensure metadata is an array (it should be cast automatically, but be explicit)
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        
        // Generate thumbnail URL if available
        $thumbnailUrl = null;
        if (!empty($metadata['thumbnail_path'])) {
            $thumbnailPath = $metadata['thumbnail_path'];
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
        if (!empty($metadata['media_metadata'])) {
            $mediaMetadata = $metadata['media_metadata'];
        }
        
        // Determine if the message is from the current user
        $isFromCurrentUser = $request->user() && $this->sender_id === $request->user()->id;
        
        // Extract filename from metadata
        $filename = $metadata['filename'] ?? $metadata['original_name'] ?? null;
        $fileSize = $metadata['file_size'] ?? $metadata['size'] ?? $this->media_size ?? null;
        
        // Extract mimetype - fallback to metadata if media_type column is null
        $mimetype = $this->media_type ?? $metadata['original_mimetype'] ?? $metadata['mimetype'] ?? null;
        
        // Log for debugging
        if ($this->type === 'document' || $this->type === 'video' || $this->type === 'audio') {
            \Log::debug('WhatsAppMessageResource: Processing file message', [
                'message_id' => $this->id,
                'type' => $this->type,
                'metadata' => $this->metadata,
                'extracted_filename' => $filename,
                'extracted_size' => $fileSize,
                'media_type_column' => $this->media_type,
                'extracted_mimetype' => $mimetype
            ]);
        }
        
        return [
            // Core message data
            'id' => $this->id,
            'sender_id' => $this->sender_id,
            'sender' => $this->sender,
            'chat_id' => $this->chat_id,
            'chat' => $this->chat,
            'type' => $this->type,
            'content' => $this->content,
            'mimetype' => $mimetype,
            'sending_time' => $this->sending_time?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            
            // Message status
            'direction' => $this->direction,
            'status' => $this->status,
            'read_at' => $this->read_at?->toIso8601String(),
            'is_read' => (bool) $this->read_at,
            'is_from_me' => $isFromCurrentUser,
            
            // File information (for documents, videos, audio)
            'filename' => $filename,
            'size' => $fileSize,
            
            // Media information
            'media' => $this->when($mediaPath || $mediaUrl || $thumbnailUrl, [
                'path' => $mediaPath,
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
