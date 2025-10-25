<?php

namespace App\Services;

use App\DataTransferObjects\WhatsAppMessageData;
use App\Models\WhatsAppMessage;
use App\Models\Chat;
use App\Models\User;
use App\Services\WebSocketService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class WhatsAppMessageService
{
    protected WebSocketService $webSocketService;

    public function __construct(WebSocketService $webSocketService)
    {
        $this->webSocketService = $webSocketService;
    }

    public function handle(WhatsAppMessageData $data, int $retryCount = 0): void
    {
        Log::channel('whatsapp')->info('WhatsAppMessageService.handle() called', [
            'type' => $data->type,
            'sender' => $data->sender,
            'retry_count' => $retryCount
        ]);
        
        try {
            // First check if message already exists
            if ($data->messageId) {
                $existingMessage = WhatsAppMessage::where('metadata->message_id', $data->messageId)->first();
                if ($existingMessage) {
                    Log::channel('whatsapp')->info('Message already exists, skipping duplicate', [
                        'message_id' => $data->messageId,
                        'existing_id' => $existingMessage->id
                    ]);
                    return;
                }
            }

            // Process with timeout protection
            DB::transaction(function() use ($data) {
                // Find or create user - but only for valid phone numbers
                Log::channel('whatsapp')->debug('About to call findOrCreateUserSafely', [
                    'sender' => $data->sender,
                    'type' => $data->type
                ]);
                $user = $this->findOrCreateUserSafely($data->sender);
                Log::channel('whatsapp')->debug('findOrCreateUserSafely returned', [
                    'user_id' => $user->id,
                    'phone' => $user->phone
                ]);


                // Find or create chat
                $chat = $this->findOrCreateChat($data->chat, $user->id);

                // Update contact info once per day if data provided by receiver
                $this->updateContactInfoIfNeeded($chat, $user, $data);

                // Associate user with chat if not already
                if (!$chat->users()->where('chat_user.user_id', $user->id)->exists()) {
                    $chat->users()->attach($user->id, ['whatsapp_id' => $data->sender]);
                }

                // Add sender_id to data
                $data->sender_id = $user->id;
                $data->chat_id = $chat->id;

                // Process message based on type
                $message = match ($data->type) {
                    'text' => $this->handleTextMessage($data),
                    'image' => $this->handleImageMessage($data),
                    'video' => $this->handleVideoMessage($data),
                    'audio' => $this->handleAudioMessage($data),
                    'document' => $this->handleDocumentMessage($data),
                    'location' => $this->handleLocationMessage($data),
                    'contact' => $this->handleContactMessage($data),
                    'reaction' => $this->handleReactionMessage($data),
                    default => $this->handleUnknownMessage($data),
                };

                // Queue WebSocket notification
                if ($message) {
                    $this->webSocketService->newMessage($message);
                    
                    // Update chat's last message reference
                    $chat->update([
                        'last_message_id' => $message->id,
                        'last_message_at' => $message->sending_time ?? now(),
                    ]);
                    
                    // Increment unread count for incoming messages
                    if ($message->direction === 'incoming') {
                        $chat->incrementUnreadCount();
                    }
                    
                    Log::channel('whatsapp')->info('Message saved successfully', [
                        'message_id' => $message->id,
                        'sender' => $data->sender,
                        'chat' => $data->chat,
                        'type' => $data->type,
                        'unread_count' => $chat->unread_count,
                    ]);
                }
            }, 3); // 3 attempts for the transaction

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error processing message', [
                'error' => $e->getMessage(),
                'retry_count' => $retryCount,
                'trace' => $e->getTraceAsString()
            ]);

            if ($retryCount < 3) {
                // Exponential backoff
                sleep(min(pow(2, $retryCount), 8));
                $this->handle($data, $retryCount + 1);
            } else {
                Log::channel('whatsapp')->critical('Max retries exceeded for message', [
                    'message_id' => $data->messageId,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    private function handleTextMessage(WhatsAppMessageData $data): WhatsAppMessage
    {
        Log::channel('whatsapp')->info("Text message received from '{$data->sender}'", [
            'message' => $data->content,
            'has_quoted_message' => !empty($data->quotedMessage),
        ]);

        // Resolve reply_to_message_id if this is a reply
        $replyToMessageId = $this->resolveReplyToMessageId($data->quotedMessage);

        return WhatsAppMessage::create([
            'sender' => $data->sender,
            'sender_id' => $data->sender_id,
            'chat' => $data->chat,
            'chat_id' => $data->chat_id,
            'type' => 'text',
            'direction' => 'incoming',
            'status' => 'delivered',
            'content' => $this->sanitizeContent($data->content),
            'reply_to_message_id' => $replyToMessageId,
            'sending_time' => $data->sending_time ?? now(),
            'metadata' => [
                'original_content' => $data->content,
                'content_length' => mb_strlen($data->content),
                'message_id' => $data->messageId,
            ],
        ]);
    }

    private function handleImageMessage(WhatsAppMessageData $data): ?WhatsAppMessage
    {
        if (empty($data->media) || empty($data->mimetype)) {
            Log::channel('whatsapp')->error("Image message from '{$data->sender}' missing 'media' or 'mimetype'");
            return null;
        }

        try {
            $imageData = base64_decode($data->media);
            if ($imageData === false) {
                throw new \Exception('Failed to decode base64 image data');
            }

            // Generate filename with directory structure for better organization
            $extension = Str::after($data->mimetype, 'image/');
            $directory = 'uploads/images/' . date('Y/m/d');
            $filename = sprintf('%s/%s.%s', $directory, Str::uuid(), $extension);

            // Ensure the directory exists
            Storage::disk('public')->makeDirectory($directory);

            // Save the file
            if (!Storage::disk('public')->put($filename, $imageData)) {
                throw new \Exception('Failed to save image to storage');
            }

            // Generate thumbnail for the image
            $thumbnailPath = $this->generateThumbnail(
                storage_path('app/public/' . $filename),
                $filename,
                $extension
            );

            // Resolve reply_to_message_id if this is a reply
            $replyToMessageId = $this->resolveReplyToMessageId($data->quotedMessage);

            return WhatsAppMessage::create([
                'sender' => $data->sender,
                'sender_id' => $data->sender_id,
                'chat' => $data->chat,
                'chat_id' => $data->chat_id,
                'type' => 'image', // Force type to image
                'direction' => 'incoming',
                'status' => 'delivered',
                'content' => $data->content ?? '', // Caption if any
                'media' => $filename,
                'media_url' => $filename,
                'mimetype' => $data->mimetype,
                'reply_to_message_id' => $replyToMessageId,
                'sending_time' => $data->sending_time ?? now(),
                'metadata' => [
                    'original_mimetype' => $data->mimetype,
                    'file_size' => Storage::disk('public')->size($filename),
                    'thumbnail_path' => $thumbnailPath,
                    'media_path' => $filename,
                    'dimensions' => $this->getImageDimensions($imageData),
                    'message_id' => $data->messageId,
                ],
            ]);

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error processing image message', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    private function handleVideoMessage(WhatsAppMessageData $data): ?WhatsAppMessage
    {
        if (empty($data->media) || empty($data->mimetype)) {
            Log::channel('whatsapp')->error("Video message from '{$data->sender}' missing 'media' or 'mimetype'");
            return null;
        }

        try {
            $videoData = base64_decode($data->media);
            if ($videoData === false) {
                throw new \Exception('Failed to decode base64 video data');
            }

            // Generate filename with directory structure
            $extension = Str::after($data->mimetype, 'video/');
            $directory = 'uploads/videos/' . date('Y/m/d');
            $filename = sprintf('%s/%s.%s', $directory, Str::uuid(), $extension);

            // Ensure the directory exists
            Storage::disk('public')->makeDirectory($directory);

            // Save the file
            if (!Storage::disk('public')->put($filename, $videoData)) {
                throw new \Exception('Failed to save video to storage');
            }

            // Generate thumbnail for the video
            $thumbnailPath = $this->generateVideoThumbnail(storage_path('app/public/' . $filename), $filename);

            // Resolve reply_to_message_id if this is a reply
            $replyToMessageId = $this->resolveReplyToMessageId($data->quotedMessage);

            return WhatsAppMessage::create([
                'sender' => $data->sender,
                'sender_id' => $data->sender_id,
                'chat' => $data->chat,
                'chat_id' => $data->chat_id,
                'type' => 'video',
                'direction' => 'incoming',
                'status' => 'delivered',
                'content' => $data->content ?? '', // Caption if any
                'media' => $filename,
                'media_url' => $filename,
                'mimetype' => $data->mimetype,
                'reply_to_message_id' => $replyToMessageId,
                'sending_time' => $data->sending_time ?? now(),
                'metadata' => [
                    'original_mimetype' => $data->mimetype,
                    'file_size' => $data->mediaSize ?? Storage::disk('public')->size($filename),
                    'thumbnail_path' => $thumbnailPath,
                    'media_path' => $filename,
                    'duration' => $this->getVideoDuration(storage_path('app/public/' . $filename)),
                    'filename' => $data->fileName,
                    'message_id' => $data->messageId,
                ],
            ]);

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error processing video message', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    private function handleAudioMessage(WhatsAppMessageData $data): ?WhatsAppMessage
    {
        if (empty($data->media) || empty($data->mimetype)) {
            Log::channel('whatsapp')->error("Audio message from '{$data->sender}' missing 'media' or 'mimetype'");
            return null;
        }

        try {
            $audioData = base64_decode($data->media);
            if ($audioData === false) {
                throw new \Exception('Failed to decode base64 audio data');
            }

            // Generate filename with directory structure
            $extension = Str::after($data->mimetype, 'audio/') ?: 'mp3';
            $directory = 'uploads/audio/' . date('Y/m/d');
            $filename = sprintf('%s/%s.%s', $directory, Str::uuid(), $extension);

            // Ensure the directory exists
            Storage::disk('public')->makeDirectory($directory);

            // Save the file
            if (!Storage::disk('public')->put($filename, $audioData)) {
                throw new \Exception('Failed to save audio to storage');
            }

            // Resolve reply_to_message_id if this is a reply
            $replyToMessageId = $this->resolveReplyToMessageId($data->quotedMessage);

            return WhatsAppMessage::create([
                'sender' => $data->sender,
                'sender_id' => $data->sender_id,
                'chat' => $data->chat,
                'chat_id' => $data->chat_id,
                'type' => 'audio',
                'direction' => 'incoming',
                'status' => 'delivered',
                'content' => $data->content ?? '',
                'media' => $filename,
                'media_url' => $filename,
                'mimetype' => $data->mimetype,
                'reply_to_message_id' => $replyToMessageId,
                'sending_time' => $data->sending_time ?? now(),
                'metadata' => [
                    'original_mimetype' => $data->mimetype,
                    'file_size' => $data->mediaSize ?? Storage::disk('public')->size($filename),
                    'media_path' => $filename,
                    'duration' => $this->getAudioDuration(storage_path('app/public/' . $filename)),
                    'filename' => $data->fileName,
                    'message_id' => $data->messageId,
                ],
            ]);

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error processing audio message', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    private function handleDocumentMessage(WhatsAppMessageData $data): ?WhatsAppMessage
    {
        if (empty($data->media) || empty($data->mimetype)) {
            Log::channel('whatsapp')->error("Document message from '{$data->sender}' missing 'media' or 'mimetype'");
            return null;
        }

        try {
            $fileData = base64_decode($data->media);
            if ($fileData === false) {
                throw new \Exception('Failed to decode base64 file data');
            }

            // Get file extension from mimetype or use a default
            $extension = $this->getExtensionFromMimeType($data->mimetype) ?? 'bin';
            $directory = 'uploads/documents/' . date('Y/m/d');
            $filename = sprintf('%s/%s.%s', $directory, Str::uuid(), $extension);

            // Ensure the directory exists
            Storage::disk('public')->makeDirectory($directory);

            // Save the file
            if (!Storage::disk('public')->put($filename, $fileData)) {
                throw new \Exception('Failed to save document to storage');
            }

            // Resolve reply_to_message_id if this is a reply
            $replyToMessageId = $this->resolveReplyToMessageId($data->quotedMessage);

            return WhatsAppMessage::create([
                'sender' => $data->sender,
                'sender_id' => $data->sender_id,
                'chat' => $data->chat,
                'chat_id' => $data->chat_id,
                'type' => 'document',
                'direction' => 'incoming',
                'status' => 'delivered',
                'content' => $data->content ?? '', // Caption or description
                'media' => $filename,
                'media_url' => $filename,
                'mimetype' => $data->mimetype,
                'reply_to_message_id' => $replyToMessageId,
                'sending_time' => $data->sending_time ?? now(),
                'metadata' => [
                    'original_mimetype' => $data->mimetype,
                    'file_size' => $data->mediaSize ?? Storage::disk('public')->size($filename),
                    'media_path' => $filename,
                    'extension' => $extension,
                    'filename' => $data->fileName ?? 'document.' . $extension,
                    'original_name' => $data->fileName ?? 'document.' . $extension,
                    'message_id' => $data->messageId,
                ],
            ]);

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error processing document message', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    private function handleLocationMessage(WhatsAppMessageData $data): ?WhatsAppMessage
    {
        if (empty($data->content)) {
            Log::channel('whatsapp')->error("Location message from '{$data->sender}' missing location data");
            return null;
        }

        try {
            $locationData = json_decode($data->content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid location data format');
            }

            return WhatsAppMessage::create([
                'sender' => $data->sender,
                'sender_id' => $data->sender_id,
                'chat' => $data->chat,
                'chat_id' => $data->chat_id,
                'type' => 'location',
                'direction' => 'incoming',
                'status' => 'delivered',
                'content' => $data->content,
                'sending_time' => $data->sending_time ?? now(),
                'metadata' => [
                    'latitude' => $locationData['latitude'] ?? null,
                    'longitude' => $locationData['longitude'] ?? null,
                    'name' => $locationData['name'] ?? null,
                    'address' => $locationData['address'] ?? null,
                    'url' => $locationData['url'] ?? null,
                    'message_id' => $data->messageId,
                ],
            ]);

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error processing location message', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'content' => $data->content,
            ]);
            return null;
        }
    }

    private function handleContactMessage(WhatsAppMessageData $data): ?WhatsAppMessage
    {
        if (empty($data->content)) {
            Log::channel('whatsapp')->error("Contact message from '{$data->sender}' missing contact data");
            return null;
        }

        try {
            $contactData = json_decode($data->content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid contact data format');
            }

            return WhatsAppMessage::create([
                'sender' => $data->sender,
                'sender_id' => $data->sender_id,
                'chat' => $data->chat,
                'chat_id' => $data->chat_id,
                'type' => 'contact',
                'direction' => 'incoming',
                'status' => 'delivered',
                'content' => $data->content,
                'sending_time' => $data->sending_time ?? now(),
                'metadata' => [
                    'name' => $contactData['name'] ?? null,
                    'phone' => $contactData['phone'] ?? null,
                    'email' => $contactData['email'] ?? null,
                    'organization' => $contactData['organization'] ?? null,
                    'title' => $contactData['title'] ?? null,
                    'message_id' => $data->messageId,
                ],
            ]);

        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error processing contact message', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'content' => $data->content,
            ]);
            return null;
        }
    }

    private function handleUnknownMessage(WhatsAppMessageData $data): ?WhatsAppMessage
    {
        Log::channel('whatsapp')->warning("Unknown message type '{$data->type}' from '{$data->sender}'", [
            'content' => $data->content,
            'mimetype' => $data->mimetype,
        ]);

        return WhatsAppMessage::create([
            'sender' => $data->sender,
            'sender_id' => $data->sender_id ?? null,
            'chat' => $data->chat,
            'chat_id' => $data->chat_id ?? null,
            'type' => 'unknown',
            'direction' => 'incoming',
            'status' => 'delivered',
            'content' => $data->content ?? json_encode($data->toArray()),
            'media' => $data->media,
            'mimetype' => $data->mimetype,
            'sending_time' => $data->sending_time ?? now(),
            'metadata' => [
                'original_type' => $data->type,
                'content_type' => gettype($data->content),
                'message_id' => $data->messageId,
            ],
        ]);
    }

    /**
     * Generate a thumbnail for an image
     */
    private function generateThumbnail(string $imagePath, string $originalPath, string $extension): ?string
    {
        try {
            // Check if GD extension is loaded (required by Intervention Image)
            if (!extension_loaded('gd')) {
                Log::channel('whatsapp')->debug('GD extension not loaded, skipping thumbnail generation');
                return null;
            }
            
            // Skip thumbnail generation for unsupported formats
            if (!in_array(strtolower($extension), ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                return null;
            }

            // Create thumbnail directory if it doesn't exist
            $thumbnailDir = 'thumbnails/' . dirname($originalPath);
            Storage::disk('public')->makeDirectory($thumbnailDir);
            
            $thumbnailPath = 'thumbnails/' . $originalPath;
            $fullThumbnailPath = storage_path('app/public/' . $thumbnailPath);
            
            // Create image instance
            $image = \Intervention\Image\Facades\Image::make($imagePath);
            
            // Resize image to max 320px width while maintaining aspect ratio
            $image->resize(320, null, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
            
            // Save the thumbnail
            $image->save($fullThumbnailPath, 80);
            
            return $thumbnailPath;
            
        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error generating thumbnail', [
                'error' => $e->getMessage(),
                'path' => $imagePath,
            ]);
            return null;
        }
    }

    /**
     * Generate a thumbnail for a video
     */
    private function generateVideoThumbnail(string $videoPath, string $originalPath): ?string
    {
        try {
            // Check if FFmpeg is available
            if (!extension_loaded('ffmpeg')) {
                return null;
            }

            $thumbnailDir = 'thumbnails/' . dirname($originalPath);
            Storage::disk('public')->makeDirectory($thumbnailDir);
            
            $thumbnailPath = 'thumbnails/' . pathinfo($originalPath, PATHINFO_FILENAME) . '.jpg';
            $fullThumbnailPath = storage_path('app/public/' . $thumbnailPath);
            
            // Use FFmpeg to capture a frame at 1 second
            $ffmpeg = \FFMpeg\FFMpeg::create([
                'ffmpeg.binaries'  => config('media.ffmpeg_path', '/usr/bin/ffmpeg'),
                'ffprobe.binaries' => config('media.ffprobe_path', '/usr/bin/ffprobe'),
                'timeout'          => 3600,
                'ffmpeg.threads'   => 12,
            ]);
            
            $video = $ffmpeg->open($videoPath);
            $frame = $video->frame(\FFMpeg\Coordinate\TimeCode::fromSeconds(1));
            $frame->save($fullThumbnailPath);
            
            return $thumbnailPath;
            
        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error generating video thumbnail', [
                'error' => $e->getMessage(),
                'path' => $videoPath,
            ]);
            return null;
        }
    }

    /**
     * Get image dimensions
     */
    private function getImageDimensions(string $imageData): ?array
    {
        try {
            // Check if GD extension is loaded
            if (!extension_loaded('gd')) {
                Log::channel('whatsapp')->warning('GD extension not loaded, cannot get image dimensions');
                return null;
            }
            
            $image = \imagecreatefromstring($imageData);
            if ($image === false) {
                return null;
            }
            
            $dimensions = [
                'width' => \imagesx($image),
                'height' => \imagesy($image),
            ];
            
            // Free up memory
            \imagedestroy($image);
            
            return $dimensions;
            
        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error getting image dimensions', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get video duration in seconds
     */
    private function getVideoDuration(string $videoPath): ?int
    {
        try {
            if (!extension_loaded('ffmpeg')) {
                return null;
            }
            
            $ffprobe = \FFMpeg\FFProbe::create([
                'ffmpeg.binaries'  => config('media.ffmpeg_path', '/usr/bin/ffmpeg'),
                'ffprobe.binaries' => config('media.ffprobe_path', '/usr/bin/ffprobe'),
            ]);
            
            return (int) $ffprobe->format($videoPath)->get('duration');
            
        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error getting video duration', [
                'error' => $e->getMessage(),
                'path' => $videoPath,
            ]);
            return null;
        }
    }

    /**
     * Get audio duration in seconds
     */
    private function getAudioDuration(string $audioPath): ?int
    {
        try {
            if (!extension_loaded('ffmpeg')) {
                return null;
            }
            
            $ffprobe = \FFMpeg\FFProbe::create([
                'ffmpeg.binaries'  => config('media.ffmpeg_path', '/usr/bin/ffmpeg'),
                'ffprobe.binaries' => config('media.ffprobe_path', '/usr/bin/ffprobe'),
            ]);
            
            return (int) $ffprobe->format($audioPath)->get('duration');
            
        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error getting audio duration', [
                'error' => $e->getMessage(),
                'path' => $audioPath,
            ]);
            return null;
        }
    }

    /**
     * Get file extension from MIME type
     */
    private function getExtensionFromMimeType(string $mimeType): ?string
    {
        $mimeToExt = [
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            'application/zip' => 'zip',
            'application/x-rar-compressed' => 'rar',
            'application/x-7z-compressed' => '7z',
        ];
        
        return $mimeToExt[strtolower($mimeType)] ?? null;
    }

    /**
     * Sanitize message content
     */
    private function sanitizeContent(?string $content): ?string
    {
        if ($content === null) {
            return null;
        }
        
        // Basic XSS protection
        $content = htmlspecialchars($content, ENT_QUOTES, 'UTF-8', false);
        
        // Remove any remaining HTML tags
        $content = strip_tags($content);
        
        // Trim whitespace
        $content = trim($content);
        
        // Convert newlines to <br> for display
        return nl2br($content);
    }

    /**
     * Find or create a chat for the given phone number
     */
    protected function findOrCreateChat(string $chatId, string $senderId): Chat
    {
        // Preserve and normalize WhatsApp JIDs
        $normalizedChatId = \App\Helpers\SecurityHelper::sanitizeJid($chatId) ?? $chatId;

        // If this is a group JID, resolve against group chats
        if (str_ends_with($normalizedChatId, '@g.us')) {
            $chat = Chat::where('is_group', true)
                ->where('metadata->whatsapp_id', $normalizedChatId)
                ->first();

            if (!$chat) {
                // Create a minimal group chat record so messages can be routed correctly
                $chat = Chat::create([
                    'name' => 'WhatsApp Group',
                    'is_group' => true,
                    'created_by' => $senderId,
                    'pending_approval' => true,
                    'participants' => [],
                    'metadata' => [
                        'whatsapp_id' => $normalizedChatId,
                        'created_by' => $senderId
                    ]
                ]);

                Log::channel('whatsapp')->info('Created new group chat from incoming message', [
                    'chat_id' => $chat->id,
                    'whatsapp_id' => $normalizedChatId,
                    'original_chat_id' => $chatId
                ]);

                $this->webSocketService->newChatCreated($chat);
            }

            // Attach operator if needed below and return
            // (fall through to user attachment logic at the end)
        } else {
            // Direct chat handling
            // Normalize incomplete/short formats to @s.whatsapp.net
            if (preg_match('/^(\+?\d+)@$/', $normalizedChatId, $matches)) {
                $normalizedChatId = ltrim($matches[1], '+') . '@s.whatsapp.net';
            } elseif (!str_contains($normalizedChatId, '@')) {
                $normalizedChatId = ltrim($normalizedChatId, '+') . '@s.whatsapp.net';
            }

            // Extract phone number for flexible searching
            $phoneNumber = preg_replace('/@.*$/', '', $normalizedChatId);

            // Try to find existing direct chat by phone number (ignoring domain variations)
            $chat = Chat::where('is_group', false)
                ->get()
                ->first(function ($c) use ($phoneNumber) {
                    $metadata = is_string($c->metadata) ? json_decode($c->metadata, true) : $c->metadata;
                    if (!$metadata || !isset($metadata['whatsapp_id'])) {
                        return false;
                    }
                    $storedPhone = preg_replace('/@.*$/', '', $metadata['whatsapp_id']);
                    return $storedPhone === $phoneNumber;
                });

            if (!$chat) {
                // Format the phone number for display (e.g., "+4917646765869")
                $displayName = '+' . $phoneNumber;

                $chat = Chat::create([
                    'name' => $displayName,
                    'is_group' => false,
                    'created_by' => $senderId,
                    'participants' => [$normalizedChatId, 'me'],
                    'pending_approval' => true,
                    'metadata' => [
                        'whatsapp_id' => $normalizedChatId,
                        'created_by' => $senderId
                    ]
                ]);

                Log::channel('whatsapp')->info('Created new direct chat', [
                    'chat_id' => $chat->id,
                    'whatsapp_id' => $normalizedChatId,
                    'original_chat_id' => $chatId
                ]);

                $this->webSocketService->newChatCreated($chat);
            }
        }

        // Ensure sender is connected to chat
        if (!$chat->users()->where('chat_user.user_id', $senderId)->exists()) {
            $chat->users()->attach($senderId);
        }

        // Ensure the app's primary operator user is connected to the chat so it appears in UI
        // This allows operators to see/manage WhatsApp-initiated chats.
        try {
            $operator = \App\Models\User::getFirstUser();
            if ($operator && (string)$operator->id !== (string)$senderId) {
                if (!$chat->users()->where('chat_user.user_id', $operator->id)->exists()) {
                    $chat->users()->attach($operator->id);
                    \Log::channel('whatsapp')->info('Attached operator to chat', [
                        'chat_id' => $chat->id,
                        'operator_user_id' => $operator->id,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            \Log::channel('whatsapp')->warning('Failed attaching operator to chat (non-fatal)', [
                'error' => $e->getMessage(),
            ]);
        }

        return $chat;
    }

    /**
     * Update contact info (profile picture, bio) for direct chats and user.
     * Applies a per-day throttle via Chat.contact_info_updated_at and also fills missing fields.
     */
    private function updateContactInfoIfNeeded(Chat $chat, User $user, WhatsAppMessageData $data): void
    {
        try {
            // Only handle direct chats
            if ($chat->is_group) {
                return;
            }

            $hasPicture = !empty($data->senderProfilePictureUrl);
            $hasBio = !empty($data->senderBio);

            if (!$hasPicture && !$hasBio) {
                Log::channel('whatsapp')->debug('No contact info provided by receiver for this message');
                return;
            }

            $lastUpdated = $chat->contact_info_updated_at;
            if (is_string($lastUpdated)) {
                // Ensure we have a Carbon instance even if attribute was not cast
                $lastUpdated = \Illuminate\Support\Carbon::parse($lastUpdated);
            }
            $updatedToday = $lastUpdated ? $lastUpdated->isSameDay(now()) : false;
            $missingChatPicture = empty($chat->contact_profile_picture_url) && $hasPicture;
            $missingChatBio = empty($chat->contact_description) && $hasBio;
            $needsDailyRefresh = !$updatedToday;

            // Decide whether to update chat fields
            $chatUpdates = [];
            if ($hasPicture && ($missingChatPicture || $needsDailyRefresh || $chat->contact_profile_picture_url !== $data->senderProfilePictureUrl)) {
                $chatUpdates['contact_profile_picture_url'] = $data->senderProfilePictureUrl;
            }
            if ($hasBio && ($missingChatBio || $needsDailyRefresh || $chat->contact_description !== $data->senderBio)) {
                $chatUpdates['contact_description'] = $data->senderBio;
            }

            Log::channel('whatsapp')->debug('Contact info update decision', [
                'chat_id' => $chat->id,
                'has_picture_in_payload' => $hasPicture,
                'has_bio_in_payload' => $hasBio,
                'last_updated' => $lastUpdated,
                'updated_today' => $updatedToday,
                'needs_daily_refresh' => $needsDailyRefresh,
                'will_update_chat' => !empty($chatUpdates),
            ]);

            if (!empty($chatUpdates)) {
                // Only set contact_info_updated_at if the column exists
                if (\Illuminate\Support\Facades\Schema::hasColumn('chats', 'contact_info_updated_at')) {
                    $chatUpdates['contact_info_updated_at'] = now();
                }
                $chat->update($chatUpdates);
                Log::channel('whatsapp')->info('Updated chat contact info', [
                    'chat_id' => $chat->id,
                    'updated_fields' => array_keys($chatUpdates),
                ]);
            }

            // Always update user's own profile fields if provided
            $userUpdates = [];
            if ($hasPicture && $user->profile_picture_url !== $data->senderProfilePictureUrl) {
                $userUpdates['profile_picture_url'] = $data->senderProfilePictureUrl;
            }
            if ($hasBio && $user->bio !== $data->senderBio) {
                $userUpdates['bio'] = $data->senderBio;
            }
            if (!empty($userUpdates)) {
                $user->update($userUpdates);
                Log::channel('whatsapp')->info('Updated user profile info from receiver payload', [
                    'user_id' => $user->id,
                    'updated_fields' => array_keys($userUpdates),
                ]);
            }
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->warning('Failed updating contact info (non-fatal)', [
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Find or create a user for the given phone number - safely
     */
    private function findOrCreateUserSafely(string $phone): User
    {
        // First try to find by phone if it exists
        $user = User::where('phone', $phone)->first();
        
        if ($user) {
            return $user;
        }
        
        // Validate phone number before creating user
        Log::channel('whatsapp')->debug('Validating phone number', [
            'phone' => $phone,
            'isValid' => $this->isValidPhoneNumber($phone)
        ]);
        
        if (!$this->isValidPhoneNumber($phone)) {
            Log::channel('whatsapp')->warning('Invalid phone number detected, using mapping approach', [
                'phone' => $phone,
            ]);
            
            // For @lid numbers, try to find the real phone number from chat_user mapping
            // This should map 150599471509579@lid to 4917646765869
            $mapping = DB::table('chat_user')
                ->where('whatsapp_id', $phone)
                ->first();
            
            if ($mapping) {
                // Found mapping, use the real user
                $user = User::find($mapping->user_id);
                if ($user) {
                    Log::channel('whatsapp')->info('Found mapped user for invalid phone', [
                        'phone' => $phone,
                        'mapped_user_id' => $user->id,
                        'real_phone' => $user->phone
                    ]);
                    return $user;
                }
            }
            
            // No mapping found, create a temporary user
            // This will be mapped later when we have the real phone number
            $user = User::where('phone', 'like', '%@lid')->first();
            
            if ($user) {
                Log::channel('whatsapp')->info('Using existing @lid user for invalid phone', [
                    'phone' => $phone,
                    'existing_user_id' => $user->id
                ]);
                return $user;
            }
            
            // Create a temporary user for @lid numbers
            try {
                $user = User::create([
                    'name' => 'Temporary User', // Special name to identify temporary users
                    'password' => bcrypt(Str::random(16)), // Random password
                    'phone' => $phone,
                    'status' => 'offline',
                    'last_seen_at' => now(),
                ]);
                
                Log::channel('whatsapp')->info('Created temporary user for invalid phone', [
                    'user_id' => $user->id,
                    'phone' => $phone,
                    'note' => 'This should be mapped to real phone number 4917646765869'
                ]);
                
                return $user;
            } catch (\Exception $e) {
                Log::channel('whatsapp')->error('Failed to create temporary user', [
                    'phone' => $phone,
                    'error' => $e->getMessage(),
                ]);
                
                // If we can't create a user, throw an exception
                throw new \InvalidArgumentException("Invalid phone number: {$phone}");
            }
        }
        
        // If not found by phone, create a new user
        try {
            $user = User::create([
                'name' => 'WhatsApp User', // Default name
                'password' => bcrypt(Str::random(16)), // Random password
                'phone' => $phone,
                'status' => 'offline',
                'last_seen_at' => now(),
            ]);
            
            Log::channel('whatsapp')->info('Created new user', [
                'user_id' => $user->id,
                'phone' => $phone,
            ]);
            
            return $user;
        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Failed to create user', [
                'phone' => $phone,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // If user creation fails, try to find an existing user by phone again
            // in case it was created by another process
            $user = User::where('phone', $phone)->first();
            
            if ($user) {
                return $user;
            }
            
            // If we still can't find the user, rethrow the exception
            throw $e;
        }
    }
    
    /**
     * Validate if a phone number is valid for user creation
     */
    private function isValidPhoneNumber(string $phone): bool
    {
        // Remove @suffix if present
        $cleanPhone = preg_replace('/@.*$/', '', $phone);
        
        // Extract only digits
        $digits = preg_replace('/[^\d]/', '', $cleanPhone);
        
        // Check if it's a reasonable phone number length
        if (strlen($digits) < 7 || strlen($digits) > 15) {
            return false;
        }
        
        // Skip obviously fake numbers (like the ones we saw)
        if (str_contains($phone, '@lid') || str_contains($phone, '@g.us')) {
            return false;
        }
        
        // Skip numbers that are too long or too short
        if (strlen($digits) > 15 || strlen($digits) < 7) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Extract real phone number from WhatsApp JID
     * Handles cases like "150599471509579@lid" -> "4917646765869"
     */
    private function extractRealPhoneNumber(string $phone): string
    {
        // For now, return the original phone number
        // TODO: Implement proper mapping from WhatsApp JID to real phone number
        // This might require looking up in a mapping table or using WhatsApp's API
        return $phone;
    }

    
    /**
     * Resolve the reply_to_message_id from quotedMessage data
     */
    private function resolveReplyToMessageId(?array $quotedMessage): ?int
    {
        if (!$quotedMessage || !isset($quotedMessage['quotedMessageId'])) {
            return null;
        }
        
        // Try to find the quoted message by its WhatsApp message ID
        $quotedWhatsAppId = $quotedMessage['quotedMessageId'];
        $quotedMsg = WhatsAppMessage::where('metadata->message_id', $quotedWhatsAppId)->first();
        
        if ($quotedMsg) {
            Log::channel('whatsapp')->info('Linked quoted message from WhatsApp', [
                'quoted_whatsapp_id' => $quotedWhatsAppId,
                'quoted_db_id' => $quotedMsg->id
            ]);
            return $quotedMsg->id;
        }
        
        Log::channel('whatsapp')->warning('Could not find quoted message', [
            'quoted_whatsapp_id' => $quotedWhatsAppId
        ]);
        
        return null;
    }
    
    /**
     * Handle reaction messages
     */
    private function handleReactionMessage(WhatsAppMessageData $data): ?WhatsAppMessage
    {
        Log::channel('whatsapp')->info('Processing reaction message', [
            'reactedMessageId' => $data->reactedMessageId ?? null,
            'emoji' => $data->emoji ?? null,
            'sender' => $data->sender,
            'sender_id' => $data->sender_id ?? null,
        ]);
        
        // Find the message that was reacted to by WhatsApp message ID
        $message = WhatsAppMessage::where('metadata->message_id', $data->reactedMessageId)
            ->first();
        
        if (!$message) {
            Log::channel('whatsapp')->warning('Message not found for reaction', [
                'reactedMessageId' => $data->reactedMessageId,
                'searched_metadata_message_id' => $data->reactedMessageId,
                'searched_id' => $data->reactedMessageId,
                'total_messages_in_db' => WhatsAppMessage::count(),
                'recent_messages_with_metadata' => WhatsAppMessage::whereNotNull('metadata')
                    ->orderBy('id', 'desc')
                    ->limit(5)
                    ->pluck('metadata', 'id')
                    ->toArray()
            ]);
            return null;
        }
        
        Log::channel('whatsapp')->info('Found message for reaction', [
            'message_id' => $message->id,
            'message_metadata' => $message->metadata,
            'current_reactions' => $message->reactions
        ]);
        
        // Get current reactions
        $reactions = $message->reactions ?? [];
        
        // If emoji is empty, remove the reaction
        if (empty($data->emoji)) {
            unset($reactions[$data->sender_id]);
            Log::channel('whatsapp')->info('Removed reaction', [
                'message_id' => $message->id,
                'user_id' => $data->sender_id
            ]);
        } else {
            // Add or update reaction
            $reactions[$data->sender_id] = $data->emoji;
            Log::channel('whatsapp')->info('Added/updated reaction', [
                'message_id' => $message->id,
                'user_id' => $data->sender_id,
                'emoji' => $data->emoji
            ]);
        }
        
        // Update message with new reactions
        $message->update([
            'reactions' => empty($reactions) ? null : $reactions
        ]);
        
        // Broadcast the reaction update via Laravel Broadcasting
        $user = User::find($data->sender_id);
        if ($user) {
            Log::channel('whatsapp')->info('Broadcasting reaction event', [
                'message_id' => $message->id,
                'chat_id' => $message->chat_id,
                'user_id' => $user->id,
                'emoji' => $data->emoji ?? '',
                'added' => !empty($data->emoji)
            ]);
            
            broadcast(new \App\Events\MessageReaction(
                $message,
                $user,
                $data->emoji ?? '',
                !empty($data->emoji)
            ));
            
            Log::channel('whatsapp')->info('Reaction event broadcasted');
            
            // Also notify via WebSocketService for compatibility
            $this->webSocketService->messageReactionUpdated(
                $message,
                (string) $data->sender_id,
                $data->emoji
            );
            
            Log::channel('whatsapp')->info('WebSocketService notified');
        } else {
            Log::channel('whatsapp')->warning('User not found for broadcasting reaction', [
                'sender_id' => $data->sender_id
            ]);
        }
        
        // Return null because reactions don't create new messages
        return null;
    }
    
    /**
     * Retry processing a failed message
     */
    private function retryMessageProcessing(WhatsAppMessageData $data, int $retryCount): void
    {
        $maxRetries = 3;
        $delay = pow(2, $retryCount) * 5; // Exponential backoff: 10s, 20s, 40s, etc.
        
        if ($retryCount > $maxRetries) {
            Log::channel('whatsapp')->error('Max retries reached for message', [
                'sender' => $data->sender,
                'chat' => $data->chat,
                'type' => $data->type,
                'retry_count' => $retryCount,
            ]);
            return;
        }
        
        // Log the retry attempt
        Log::channel('whatsapp')->info('Retrying message processing', [
            'sender' => $data->sender,
            'chat' => $data->chat,
            'type' => $data->type,
            'attempt' => $retryCount,
            'next_attempt_in_seconds' => $delay,
        ]);
        
        // Wait before retrying
        sleep($delay);
        
        // Try processing again
        try {
            $this->handle($data);
        } catch (\Exception $e) {
            $this->retryMessageProcessing($data, $retryCount + 1);
        }
    }
}
