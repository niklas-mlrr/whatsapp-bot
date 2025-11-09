<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppMessage;
use App\Models\User;
use App\Models\Chat;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Resources\WhatsAppMessageResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WhatsAppMessageController extends Controller
{
    /**
     * Get file extension from mimetype
     */
    private function getExtensionFromMimetype(string $mimetype): string
    {
        return match($mimetype) {
            // Images
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/svg+xml' => 'svg',
            'image/bmp' => 'bmp',
            // Documents
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/zip' => 'zip',
            'application/x-rar-compressed' => 'rar',
            'application/x-7z-compressed' => '7z',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            'application/json' => 'json',
            'application/xml' => 'xml',
            'text/xml' => 'xml',
            // Videos
            'video/mp4' => 'mp4',
            'video/mpeg' => 'mpeg',
            'video/quicktime' => 'mov',
            'video/x-msvideo' => 'avi',
            'video/x-matroska' => 'mkv',
            'video/webm' => 'webm',
            // Audio
            'audio/mpeg' => 'mp3',
            'audio/ogg' => 'ogg',
            'audio/wav' => 'wav',
            'audio/webm' => 'webm',
            'audio/aac' => 'aac',
            'audio/x-m4a' => 'm4a',
            // Default
            default => $this->extractExtensionFromMimetype($mimetype)
        };
    }
    
    /**
     * Extract extension from mimetype string (e.g., "application/pdf" -> "pdf")
     */
    private function extractExtensionFromMimetype(string $mimetype): string
    {
        if (str_contains($mimetype, '/')) {
            $parts = explode('/', $mimetype);
            $extension = end($parts);
            // Remove any additional parameters (e.g., "vnd.ms-excel" -> "excel")
            $extension = preg_replace('/^(x-|vnd\.)/', '', $extension);
            // Take only the last part if there are dots
            if (str_contains($extension, '.')) {
                $parts = explode('.', $extension);
                $extension = end($parts);
            }
            return $extension;
        }
        return 'bin';
    }
    
    // GET /api/messages
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'chat' => 'nullable|string|max:255',
            'sender' => 'nullable|string|max:255',
            'type' => 'nullable|string|in:text,image,video,audio,document,location,contact,unknown,poll',
            'direction' => 'nullable|string|in:incoming,outgoing',
            'status' => 'nullable|string|in:pending,sent,delivered,read,failed',
            'search' => 'nullable|string|max:255',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
            'sort_by' => 'nullable|string|in:sending_time,created_at,updated_at',
            'sort_order' => 'nullable|string|in:asc,desc',
        ]);

        // Include soft-deleted messages to show deletion placeholders
        $query = WhatsAppMessage::withTrashed();

        // Apply filters
        if ($request->filled('chat')) {
            $query->where('metadata->chat', $validated['chat']);
        }
        
        if ($request->filled('sender')) {
            $query->where('metadata->sender', $validated['sender']);
        }
        
        if ($request->filled('type')) {
            $query->where('type', $validated['type']);
        }
        
        if ($request->filled('direction')) {
            $query->where('direction', $validated['direction']);
        }
        
        if ($request->filled('status')) {
            $query->where('status', $validated['status']);
        }
        
        // Date range filter
        if ($request->filled('from')) {
            $query->where('sending_time', '>=', $validated['from']);
        }
        
        if ($request->filled('to')) {
            $query->where('sending_time', '<=', $validated['to'] . ' 23:59:59');
        }
        
        // Search in content
        if ($request->filled('search')) {
            $searchTerm = '%' . $validated['search'] . '%';
            $query->where(function($q) use ($searchTerm) {
                $q->where('content', 'like', $searchTerm)
                  ->orWhere('sender', 'like', $searchTerm)
                  ->orWhere('chat', 'like', $searchTerm);
            });
        }

        // Sorting
        $sortBy = $validated['sort_by'] ?? 'sending_time';
        $sortOrder = $validated['sort_order'] ?? 'desc';
        
        // Map sending_time to created_at since sending_time is an accessor, not a column
        $databaseColumn = $sortBy === 'sending_time' ? 'created_at' : $sortBy;
        $query->orderBy($databaseColumn, $sortOrder);

        // Pagination
        $perPage = $validated['per_page'] ?? 20;
        $messages = $query->paginate($perPage);

        return WhatsAppMessageResource::collection($messages)->response();
    }

    // GET /api/messages/{id}
    public function show($id): JsonResponse
    {
        $message = WhatsAppMessage::with(['pollVotes'])->findOrFail($id);
        return (new WhatsAppMessageResource($message))->response();
    }

    // DELETE /api/messages/{id}
    public function destroy($id): JsonResponse
    {
        try {
            $message = WhatsAppMessage::findOrFail($id);
            $chatId = $message->chat_id;
            $user = User::getFirstUser();
            
            // Store message info before deletion
            $messageId = $message->id;
            $whatsappMessageId = $message->metadata['message_id'] ?? null;
            
            // Delete the message from database
            $message->delete();
            
            // Send delete request to WhatsApp (delete for everyone)
            if ($whatsappMessageId) {
                try {
                    $receiverUrl = config('app.receiver_url', env('RECEIVER_URL', 'http://127.0.0.1:3000'));
                    $receiverUrl = rtrim($receiverUrl, '/');
                    
                    $http = \Illuminate\Support\Facades\Http::timeout(10)
                        ->withHeaders([
                            'Accept' => 'application/json',
                            'Content-Type' => 'application/json',
                            'X-API-Key' => config('app.receiver_api_key', ''),
                        ]);
                    
                    $isHttps = str_starts_with(strtolower($receiverUrl), 'https://');
                    $allowInsecure = (bool) env('RECEIVER_TLS_INSECURE', false);
                    if ($isHttps && $allowInsecure) {
                        $http = $http->withoutVerifying();
                    }
                    
                    // Get chat JID from message metadata
                    $chatJid = $message->metadata['chat'] ?? null;
                    
                    $response = $http->post("{$receiverUrl}/delete-message", [
                        'messageId' => $whatsappMessageId,
                        'chatJid' => $chatJid,
                        'forEveryone' => true
                    ]);
                    
                    if (!$response->successful()) {
                        \Log::warning('Failed to delete message on WhatsApp', [
                            'message_id' => $whatsappMessageId,
                            'response' => $response->body()
                        ]);
                    }
                } catch (\Exception $e) {
                    \Log::error('Error sending delete request to WhatsApp', [
                        'error' => $e->getMessage(),
                        'message_id' => $whatsappMessageId
                    ]);
                }
            }
            
            // Broadcast deletion event
            broadcast(new \App\Events\MessageDeleted(
                $messageId,
                $chatId,
                $user,
                true // forEveryone
            ))->toOthers();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Message deleted for everyone'
            ]);
        } catch (\Exception $e) {
            \Log::error('Error deleting message', [
                'error' => $e->getMessage(),
                'message_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete message: ' . $e->getMessage()
            ], 500);
        }
    }
    
    // PUT /api/messages/{id}
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $message = WhatsAppMessage::findOrFail($id);
            $user = User::getFirstUser();
            
            $validated = $request->validate([
                'content' => 'required|string|max:4096',
            ]);
            
            // Store original content
            $originalContent = $message->content;
            $whatsappMessageId = $message->metadata['message_id'] ?? null;
            
            // Update message content
            $message->update([
                'content' => $validated['content'],
                'edited_at' => now()
            ]);
            
            // Send edit request to WhatsApp
            if ($whatsappMessageId) {
                try {
                    $receiverUrl = config('app.receiver_url', env('RECEIVER_URL', 'http://127.0.0.1:3000'));
                    $receiverUrl = rtrim($receiverUrl, '/');
                    
                    $http = \Illuminate\Support\Facades\Http::timeout(10)
                        ->withHeaders([
                            'Accept' => 'application/json',
                            'Content-Type' => 'application/json',
                            'X-API-Key' => config('app.receiver_api_key', ''),
                        ]);
                    
                    $isHttps = str_starts_with(strtolower($receiverUrl), 'https://');
                    $allowInsecure = (bool) env('RECEIVER_TLS_INSECURE', false);
                    if ($isHttps && $allowInsecure) {
                        $http = $http->withoutVerifying();
                    }
                    
                    // Get chat JID from message metadata
                    $chatJid = $message->metadata['chat'] ?? null;
                    
                    $response = $http->post("{$receiverUrl}/edit-message", [
                        'messageId' => $whatsappMessageId,
                        'chatJid' => $chatJid,
                        'newContent' => $validated['content']
                    ]);
                    
                    if (!$response->successful()) {
                        \Log::warning('Failed to edit message on WhatsApp', [
                            'message_id' => $whatsappMessageId,
                            'response' => $response->body()
                        ]);
                    }
                } catch (\Exception $e) {
                    \Log::error('Error sending edit request to WhatsApp', [
                        'error' => $e->getMessage(),
                        'message_id' => $whatsappMessageId
                    ]);
                }
            }
            
            // Broadcast edit event
            broadcast(new \App\Events\MessageEdited(
                $message,
                $user,
                $originalContent
            ))->toOthers();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Message edited successfully',
                'data' => new WhatsAppMessageResource($message)
            ]);
        } catch (\Exception $e) {
            \Log::error('Error editing message', [
                'error' => $e->getMessage(),
                'message_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to edit message: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update contact info if needed
     */
    private function updateContactInfoIfNeeded(Chat $chat, array $data): void
    {
        // Normalize contact_info_updated_at to Carbon instance if needed
        $lastUpdated = $chat->contact_info_updated_at;
        if (is_string($lastUpdated)) {
            $lastUpdated = \Illuminate\Support\Carbon::parse($lastUpdated);
        }

        // Debug log the received data
        \Log::debug('Updating contact info', [
            'chat_id' => $chat->id,
            'received_data' => array_keys($data),
            'has_profile_picture' => !empty($data['senderProfilePictureUrl']),
            'has_bio' => !empty($data['senderBio']),
            'current_updated_at' => $lastUpdated,
            'should_update' => !$lastUpdated || $lastUpdated->lt(now()->subDay())
        ]);

        // Skip if no contact info is provided
        if (empty($data['senderProfilePictureUrl']) && empty($data['senderBio'])) {
            \Log::debug('No contact info provided to update');
            return;
        }

        // This method is now deprecated - contact info is handled in the store method
        // by creating/updating contacts table entries
        \Log::debug('Contact info update called (deprecated - now handled via contacts table)');
    }

    // POST /api/messages
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sender' => 'required|string',
            'chat' => 'required|string',
            'type' => 'required|string',
            'content' => 'nullable|string',
            'media' => 'nullable|string',
            'mimetype' => 'nullable|string',
            'sending_time' => 'nullable|date',
            'filename' => 'nullable|string',
            'size' => 'nullable|integer',
            'reply_to_message_id' => 'nullable|integer|exists:whatsapp_messages,id',
            // Quoted message from WhatsApp (incoming)
            'quotedMessage' => 'nullable|array',
            'quotedMessage.quotedMessageId' => 'nullable|string',
            'quotedMessage.quotedContent' => 'nullable|string',
            'quotedMessage.quotedSender' => 'nullable|string',
            // Reaction-specific fields
            'reactedMessageId' => 'nullable|string',
            'emoji' => 'nullable|string',
            'senderJid' => 'nullable|string',
            // Sender profile info
            'senderProfilePictureUrl' => 'nullable|url',
            'senderBio' => 'nullable|string|max:500',
            // Poll data
            'pollData' => 'nullable|array',
            'pollData.name' => 'nullable|string|max:255',
            'pollData.options' => 'nullable|array|min:2|max:12',
            'pollData.options.*.optionName' => 'nullable|string|max:100',
            'pollData.options.*.name' => 'nullable|string|max:100',
            'pollData.pollType' => 'nullable|string|in:POLL',
            'pollData.pollContentType' => 'nullable|string|in:TEXT',
            'pollData.selectableOptionsCount' => 'nullable|integer|min:0',
        ]);
        
        // Handle reaction messages separately
        if ($data['type'] === 'reaction') {
            return $this->handleReaction($data);
        }
        
        // Handle poll update messages (votes) separately
        if ($data['type'] === 'poll_update') {
            return $this->handlePollUpdate($data);
        }
        if (empty($data['sending_time'])) {
            $data['sending_time'] = now();
        }
        // Resolve sender and chat to IDs required by whatsapp_messages schema
        // 1) Resolve or create user by phone - safely
        $user = $this->findOrCreateUserSafely($data['sender']);
        
        // Create or update contact entry with profile info
        $this->createOrUpdateContact($user, $data);
        
        // Also update user profile for backward compatibility
        if (!empty($data['senderProfilePictureUrl']) || !empty($data['senderBio'])) {
            $updateData = [];
            if (!empty($data['senderProfilePictureUrl'])) {
                $updateData['profile_picture_url'] = $data['senderProfilePictureUrl'];
            }
            if (!empty($data['senderBio'])) {
                $updateData['bio'] = $data['senderBio'];
            }
            if (!empty($updateData)) {
                $user->update($updateData);
                \Log::info('Updated user profile info', [
                    'user_id' => $user->id,
                    'phone' => $data['sender'],
                    'has_picture' => !empty($data['senderProfilePictureUrl']),
                    'has_bio' => !empty($data['senderBio'])
                ]);
            }
        }

        // 2) Resolve or create chat by WhatsApp JID stored in metadata->whatsapp_id
        $chatJidRaw = $data['chat'];
        $chatJid = \App\Helpers\SecurityHelper::sanitizeJid($chatJidRaw) ?? $chatJidRaw;

        if (str_ends_with($chatJid, '@g.us')) {
            // Group chat: find by whatsapp_id
            $chat = Chat::where('is_group', true)
                ->where('metadata->whatsapp_id', $chatJid)
                ->first();

            if (!$chat) {
                // Create a minimal group chat so the message associates correctly
                $chat = Chat::create([
                    'name' => 'WhatsApp Group',
                    'is_group' => true,
                    'created_by' => $user->id,
                    'participants' => [],
                    'metadata' => [
                        'whatsapp_id' => $chatJid,
                        'created_by' => $user->id,
                    ],
                ]);
            }
        } else {
            // Direct chat handling: normalize to @s.whatsapp.net when needed
            $normalizedChatId = $chatJid;
            if (preg_match('/^(\+?\d+)@$/', $normalizedChatId, $matches)) {
                $normalizedChatId = ltrim($matches[1], '+') . '@s.whatsapp.net';
            } elseif (!str_contains($normalizedChatId, '@')) {
                $normalizedChatId = ltrim($normalizedChatId, '+') . '@s.whatsapp.net';
            }

            // Extract phone number for flexible searching
            $phoneNumber = preg_replace('/@.*$/', '', $normalizedChatId);

            // Try to find existing chat by phone number (handles format variations)
            $chat = Chat::where('is_group', false)
                ->get()
                ->first(function($c) use ($phoneNumber) {
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
                    'created_by' => $user->id,
                    'participants' => [$normalizedChatId, 'me'],
                    'metadata' => [
                        'whatsapp_id' => $normalizedChatId,
                        'created_by' => $user->id,
                    ],
                ]);
            }
        }

        // Optional: attach user to chat (ignore if exists)
        try {
            if (!$chat->users()->where('chat_user.user_id', $user->id)->exists()) {
                $chat->users()->attach($user->id);
            }
        } catch (\Throwable $e) {
            // non-fatal
        }

        // Map media fields to schema (media_url/media_type)
        $mediaUrl = null;
        if (!empty($data['media'])) {
            // Check if it's base64 data
            if (preg_match('/^[A-Za-z0-9+\/]+=*$/', $data['media']) && strlen($data['media']) > 100) {
                // It's base64 data without data URI prefix - decode and save
                try {
                    $decodedData = base64_decode($data['media'], true);
                    if ($decodedData !== false) {
                        // Generate a unique filename
                        $extension = $this->getExtensionFromMimetype($data['mimetype'] ?? 'application/octet-stream');
                        $filename = 'uploads/' . \Str::random(40) . '.' . $extension;
                        
                        // Save to storage
                        \Storage::disk('public')->put($filename, $decodedData);
                        $mediaUrl = $filename;
                        
                        \Log::info('Saved base64 image to storage', [
                            'filename' => $filename,
                            'size' => strlen($decodedData)
                        ]);
                    }
                } catch (\Exception $e) {
                    \Log::error('Failed to decode and save base64 image', [
                        'error' => $e->getMessage(),
                        'data_length' => strlen($data['media'])
                    ]);
                }
            }
            // Check if it's a data URI
            else if (str_starts_with($data['media'], 'data:')) {
                // Extract base64 data from data URI
                if (preg_match('/^data:([^;]+);base64,(.+)$/', $data['media'], $matches)) {
                    try {
                        $decodedData = base64_decode($matches[2], true);
                        if ($decodedData !== false) {
                            // Generate a unique filename
                            $extension = $this->getExtensionFromMimetype($data['mimetype'] ?? $matches[1]);
                            $filename = 'uploads/' . \Str::random(40) . '.' . $extension;
                            
                            // Save to storage
                            \Storage::disk('public')->put($filename, $decodedData);
                            $mediaUrl = $filename;
                            
                            \Log::info('Saved data URI image to storage', [
                                'filename' => $filename,
                                'size' => strlen($decodedData)
                            ]);
                        }
                    } catch (\Exception $e) {
                        \Log::error('Failed to decode and save data URI image', [
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }
            // If it's already a full URL, keep as-is
            else if (filter_var($data['media'], FILTER_VALIDATE_URL)) {
                $mediaUrl = $data['media'];
            }
            // Otherwise assume it's a path (for messages sent from frontend)
            else {
                $mediaUrl = $data['media'];
            }
        }

        $metadata = [
            'sender' => $data['sender'],
            'chat' => $data['chat'],
            'sending_time' => (string) $data['sending_time'],
        ];

        if ($mediaUrl) {
            $metadata['media_path'] = $mediaUrl;
        }

        if (!empty($data['filename'])) {
            $metadata['filename'] = $data['filename'];
        }

        if (!empty($data['size'])) {
            $metadata['file_size'] = $data['size'];
        }
        
        if ($data['type'] === 'poll' && !empty($data['pollData'])) {
            \Log::info('Storing poll data', [
                'pollData' => $data['pollData'],
                'selectableOptionsCount' => $data['pollData']['selectableOptionsCount'] ?? 'NOT SET',
                'hasSelectableOptionsCount' => isset($data['pollData']['selectableOptionsCount'])
            ]);
            
            $metadata['poll_data'] = $data['pollData'];
            
            // Initialize vote counts for each option
            $voteCounts = [];
            $options = $data['pollData']['options'] ?? [];
            foreach ($options as $index => $option) {
                $voteCounts[strval($index)] = 0;
            }
            $metadata['poll_vote_counts'] = $voteCounts;
        }
        
        \Log::info('Message metadata prepared', [
            'metadata' => $metadata,
            'has_filename' => !empty($data['filename']),
            'filename_value' => $data['filename'] ?? 'not set'
        ]);

        // Send to receiver
        try {
            $receiverUrl = config('app.receiver_url', env('RECEIVER_URL', 'http://127.0.0.1:3000'));

            if (empty($receiverUrl)) {
                \Log::error('Receiver URL is not configured.');
                return response()->json([
                    'status' => 'error',
                    'message' => 'Receiver URL not configured',
                ], 500);
            }
            $receiverUrl = rtrim($receiverUrl, '/');
            
            \Log::info('Sending message to receiver', [
                'receiver_url' => $receiverUrl,
                'data' => $data
            ]);
            
            $sendPayload = [
                'chat' => $data['chat'],
                'type' => $data['type'],
                'content' => $data['content'] ?? '',
                'media' => null,
                'mimetype' => $data['mimetype'] ?? null,
            ];

            if (!empty($data['filename'])) {
                $sendPayload['filename'] = $data['filename'];
            }

            if (!empty($data['size'])) {
                $sendPayload['size'] = $data['size'];
            }
            
            // Include poll data if this is a poll message
            if ($data['type'] === 'poll' && !empty($data['pollData'])) {
                $sendPayload['pollData'] = $data['pollData'];
            }
            
            // Include reply_to_message_id if this is a reply
            if (!empty($data['reply_to_message_id'])) {
                // Fetch the quoted message to get its WhatsApp message ID
                $quotedMessage = WhatsAppMessage::find($data['reply_to_message_id']);
                if ($quotedMessage && isset($quotedMessage->metadata['message_id'])) {
                    $sendPayload['quoted_message_whatsapp_id'] = $quotedMessage->metadata['message_id'];
                    $sendPayload['quoted_message_content'] = $quotedMessage->content ?? '';
                    // Check if the quoted message was sent by the authenticated user
                    $sendPayload['quoted_message_from_me'] = $quotedMessage->sender_id === $user->id;
                    
                    \Log::info('Including quoted message data in payload', [
                        'quoted_db_id' => $data['reply_to_message_id'],
                        'quoted_whatsapp_id' => $quotedMessage->metadata['message_id'],
                        'quoted_from_me' => $sendPayload['quoted_message_from_me']
                    ]);
                }
            }

            // If this is an image message, include the full path to the media
            $requiresMediaBase64 = in_array($data['type'], ['image', 'document', 'video', 'audio']);

            if ($requiresMediaBase64 && !empty($data['media'])) {
                \Log::info('Processing media for outbound message', [
                    'type' => $data['type'],
                    'media' => $data['media'],
                    'exists' => Storage::disk('public')->exists($data['media'])
                ]);

                if (filter_var($data['media'], FILTER_VALIDATE_URL)) {
                    $sendPayload['media'] = $data['media'];
                } elseif (Storage::disk('public')->exists($data['media'])) {
                    $filePath = Storage::disk('public')->path($data['media']);

                    \Log::info('Attempting to read file', [
                        'storage_path' => $filePath,
                        'relative_path' => $data['media']
                    ]);

                    if (!file_exists($filePath)) {
                        \Log::error('File does not exist at path', ['path' => $filePath]);
                        throw new \Exception('File does not exist at path: ' . $filePath);
                    }

                    $fileContents = file_get_contents($filePath);
                    $base64 = base64_encode($fileContents);
                    $mimeType = $data['mimetype'] ?? mime_content_type($filePath) ?? 'application/octet-stream';
                    $sendPayload['media'] = 'data:' . $mimeType . ';base64,' . $base64;

                    \Log::info('Converted local file to base64', [
                        'encoded_size' => strlen($base64),
                        'file' => basename($filePath),
                        'mime' => $mimeType,
                    ]);
                }

                if (empty($sendPayload['media'])) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Invalid media file',
                        'media_path' => $data['media'],
                        'storage_exists' => Storage::disk('public')->exists($data['media'])
                    ], 400);
                }

                \Log::info('Prepared outbound media', ['type' => $data['type']]);
            }

            \Log::info('Sending payload to receiver', $sendPayload);
            
            // Build HTTP client, allow insecure TLS if explicitly enabled for self-signed certs
            $http = Http::timeout(30)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-API-Key' => config('app.receiver_api_key', ''),
                ]);

            $isHttps = str_starts_with(strtolower($receiverUrl), 'https://');
            $allowInsecure = (bool) env('RECEIVER_TLS_INSECURE', false);
            if ($isHttps && $allowInsecure) {
                $http = $http->withoutVerifying();
            }

            $response = $http->post("{$receiverUrl}/send-message", $sendPayload);
            
            if (!$response->successful()) {
                $errorResponse = [
                    'status' => 'error',
                    'message' => 'Failed to send message to WhatsApp',
                    'receiver_status' => $response->status(),
                    'receiver_response' => $response->body(),
                    'receiver_url' => $receiverUrl,
                    'payload' => $sendPayload
                ];
                
                \Log::error('Failed to send message to receiver', $errorResponse);
                
                return response()->json($errorResponse, 500);
            }
            
            // Get the WhatsApp message ID from the receiver response
            $receiverData = $response->json();
            $whatsappMessageId = $receiverData['messageId'] ?? null;
            
            // Add the WhatsApp message ID to metadata
            if ($whatsappMessageId) {
                $metadata['message_id'] = $whatsappMessageId;
            }
            
            // Handle quoted message from incoming WhatsApp messages
            $replyToMessageId = $data['reply_to_message_id'] ?? null;
            if (!$replyToMessageId && isset($data['quotedMessage']['quotedMessageId'])) {
                // Try to find the quoted message by its WhatsApp message ID
                $quotedWhatsAppId = $data['quotedMessage']['quotedMessageId'];
                $quotedMessage = WhatsAppMessage::where('metadata->message_id', $quotedWhatsAppId)->first();
                
                if ($quotedMessage) {
                    $replyToMessageId = $quotedMessage->id;
                    \Log::info('Linked quoted message from WhatsApp', [
                        'quoted_whatsapp_id' => $quotedWhatsAppId,
                        'quoted_db_id' => $quotedMessage->id
                    ]);
                } else {
                    \Log::warning('Could not find quoted message', [
                        'quoted_whatsapp_id' => $quotedWhatsAppId
                    ]);
                }
            }
            
            // If we get here, the message was sent successfully to WhatsApp
            // Set status to 'delivered' since the receiver confirmed it was sent
            $message = WhatsAppMessage::create([
                'sender_id' => $user->id,
                'chat_id' => $chat->id,
                'reply_to_message_id' => $replyToMessageId,
                'type' => $data['type'],
                'status' => 'delivered', // Changed from 'sent' to 'delivered' since receiver confirmed delivery
                'content' => $data['content'] ?? '',
                'media_url' => $mediaUrl,
                'media_type' => $data['mimetype'] ?? null,
                'metadata' => $metadata,
            ]);
            
            // Load relationships for proper accessor functionality
            $message->load(['senderUser', 'replyToMessage.senderUser']);
            
            \Log::info('Message created successfully', [
                'message_id' => $message->id,
                'metadata' => $message->metadata,
                'has_filename_in_metadata' => isset($message->metadata['filename'])
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Message sent successfully',
                'data' => new WhatsAppMessageResource($message)
            ]);
        } catch (\Exception $e) {
            \Log::error('Error sending message to receiver', [
                'exception' => $e->getMessage(),
                'receiver_url' => $receiverUrl,
                'payload' => $sendPayload
            ]);
            return response()->json(['status' => 'error', 'message' => 'Internal Server Error'], 500);
        }
    }
    
    // GET /api/chats - Get list of chats with metadata
    public function chats(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'search' => 'nullable|string|max:255',
                'unread_only' => 'nullable|boolean',
                'per_page' => 'nullable|integer|min:1|max:100',
                'page' => 'nullable|integer|min:1',
            ]);
            
            // Subquery to get the last message for each chat
            $latestMessages = WhatsAppMessage::selectRaw('MAX(id) as last_message_id')
                ->groupBy('chat');
                
            // Main query to get chat metadata
            $query = WhatsAppMessage::with(['senderUser', 'chat', 'pollVotes'])
                ->select([
                    'chat',
                    'sender',
                    'sending_time as last_message_time',
                    'content as last_message_content',
                    'type as last_message_type',
                    'status as last_message_status',
                    'read_at as last_message_read_at',
                    \DB::raw('(SELECT COUNT(*) FROM messages AS unread_messages WHERE unread_messages.chat = messages.chat AND unread_messages.read_at IS NULL) as unread_count'),
                ])
                ->whereIn('id', $latestMessages)
                ->orderBy('sending_time', 'desc');
            
            // Apply search filter
            if ($request->filled('search')) {
                $searchTerm = '%' . $validated['search'] . '%';
                $query->where('chat', 'like', $searchTerm);
            }
            
            // Filter unread only
            if ($request->boolean('unread_only')) {
                $query->having('unread_count', '>', 0);
            }
            
            // Pagination
            $perPage = $validated['per_page'] ?? 20;
            $chats = $query->paginate($perPage);
            
            // Transform the results to include participants (assuming chat is a single participant for now)
            $chats->getCollection()->transform(function ($chat) {
                $chat->participants = [$chat->sender];
                return $chat;
            });
            
            return ChatResource::collection($chats);
            
        } catch (\Exception $e) {
            \Log::error('Failed to fetch chats', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch chats',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Handle image uploads
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function upload(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'file' => 'required|file|max:51200', // max 50MB
            ]);

            $uploadedFile = $request->file('file');

            $mimeType = $uploadedFile->getMimeType();
            $originalName = $uploadedFile->getClientOriginalName();
            $size = $uploadedFile->getSize();

            $directory = match (true) {
                str_starts_with((string) $mimeType, 'image/') => 'uploads/images',
                str_starts_with((string) $mimeType, 'video/') => 'uploads/videos',
                str_starts_with((string) $mimeType, 'audio/') => 'uploads/audio',
                default => 'uploads/files',
            };

            $path = $uploadedFile->store($directory, 'public');

            $url = url(Storage::url($path));

            if (strpos($url, 'http') !== 0) {
                $url = url($url);
            }

            return response()->json([
                'status' => 'success',
                'path' => $path,
                'url' => $url,
                'mimetype' => $mimeType,
                'original_name' => $originalName,
                'size' => $size,
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to upload image', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to upload image',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Handle incoming reaction messages
     * 
     * @param array $data
     * @return JsonResponse
     */
    private function handleReaction(array $data): JsonResponse
    {
        try {
            \Log::info('Processing reaction', [
                'reactedMessageId' => $data['reactedMessageId'] ?? null,
                'emoji' => $data['emoji'] ?? null,
                'sender' => $data['sender'] ?? null,
            ]);
            
            // Find the message by WhatsApp message ID stored in metadata
            $message = WhatsAppMessage::where('metadata->message_id', $data['reactedMessageId'])
                ->orWhere('id', $data['reactedMessageId'])
                ->first();
            
            if (!$message) {
                \Log::warning('Message not found for reaction', [
                    'reactedMessageId' => $data['reactedMessageId']
                ]);
                
                return response()->json([
                    'status' => 'error',
                    'message' => 'Message not found'
                ], 404);
            }
            
            // Get or create user safely
            $senderPhone = $data['senderJid'] ?? $data['sender'];
            $user = $this->findOrCreateUserSafely($senderPhone);
            
            // Get current reactions
            $reactions = $message->reactions ?? [];
            
            // If emoji is empty, remove the reaction
            if (empty($data['emoji'])) {
                unset($reactions[$user->id]);
                \Log::info('Removed reaction', [
                    'message_id' => $message->id,
                    'user_id' => $user->id
                ]);
            } else {
                // Add or update reaction
                $reactions[$user->id] = $data['emoji'];
                \Log::info('Added/updated reaction', [
                    'message_id' => $message->id,
                    'user_id' => $user->id,
                    'emoji' => $data['emoji']
                ]);
            }
            
            // Update message with new reactions
            $message->update([
                'reactions' => empty($reactions) ? null : $reactions
            ]);
            
            // Broadcast the reaction update via WebSocket
            broadcast(new \App\Events\MessageReaction(
                $message,
                $user,
                $data['emoji'] ?? '',
                !empty($data['emoji'])
            ))->toOthers();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Reaction processed',
                'data' => [
                    'message_id' => $message->id,
                    'reactions' => $reactions,
                ]
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error processing reaction', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process reaction',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Handle incoming poll update messages (votes)
     * 
     * @param array $data
     * @return JsonResponse
     */
    private function handlePollUpdate(array $data): JsonResponse
    {
        try {
            \Log::info('Processing poll update (vote)', [
                'pollMessageId' => $data['pollMessageId'] ?? null,
                'sender' => $data['sender'] ?? null,
                'senderTimestampMs' => $data['senderTimestampMs'] ?? null,
                'selectedOptionIndex' => $data['selectedOptionIndex'] ?? null,
                'selectedOptions' => $data['selectedOptions'] ?? [],
            ]);
            
            // Find the poll message by WhatsApp message ID stored in metadata
            $pollMessage = WhatsAppMessage::where('metadata->message_id', $data['pollMessageId'])
                ->orWhere('id', $data['pollMessageId'])
                ->first();
            
            if (!$pollMessage) {
                \Log::warning('Poll message not found for vote', [
                    'pollMessageId' => $data['pollMessageId']
                ]);
                
                return response()->json([
                    'status' => 'error',
                    'message' => 'Poll message not found'
                ], 404);
            }
            
            // Verify this is actually a poll message
            if ($pollMessage->type !== 'poll') {
                \Log::warning('Message is not a poll', [
                    'message_id' => $pollMessage->id,
                    'message_type' => $pollMessage->type
                ]);
                
                return response()->json([
                    'status' => 'error',
                    'message' => 'Message is not a poll'
                ], 400);
            }
            
            // Get or create user safely
            $senderPhone = $data['senderJid'] ?? $data['sender'];
            $user = $this->findOrCreateUserSafely($senderPhone);
            
            // Extract poll data from metadata
            $pollData = $pollMessage->metadata['poll_data'] ?? [];
            $options = $pollData['options'] ?? [];
            
            if (empty($options)) {
                \Log::warning('Poll has no options', [
                    'message_id' => $pollMessage->id
                ]);
                
                return response()->json([
                    'status' => 'error',
                    'message' => 'Poll has no options'
                ], 400);
            }
            
            // Get current vote counts from metadata
            $metadata = $pollMessage->metadata;
            $voteCounts = $metadata['poll_vote_counts'] ?? [];
            
            // Initialize vote counts if not present
            foreach ($options as $index => $option) {
                if (!isset($voteCounts[strval($index)])) {
                    $voteCounts[strval($index)] = 0;
                }
            }
            
            // Process the vote
            $votedOptions = [];
            
            if (isset($data['selectedOptionIndex']) && $data['selectedOptionIndex'] !== null) {
                // Single vote
                $optionIndex = $data['selectedOptionIndex'];
                if ($optionIndex >= 0 && $optionIndex < count($options)) {
                    $votedOptions[] = $optionIndex;
                    $voteCounts[strval($optionIndex)]++;
                } else {
                    \Log::warning('Invalid option index for poll vote', [
                        'option_index' => $optionIndex,
                        'options_count' => count($options)
                    ]);
                }
            } elseif (!empty($data['selectedOptions']) && is_array($data['selectedOptions'])) {
                // Multiple votes
                foreach ($data['selectedOptions'] as $optionIndex) {
                    if ($optionIndex >= 0 && $optionIndex < count($options)) {
                        $votedOptions[] = $optionIndex;
                        $voteCounts[strval($optionIndex)]++;
                    } else {
                        \Log::warning('Invalid option index in multiple poll votes', [
                            'option_index' => $optionIndex,
                            'options_count' => count($options)
                        ]);
                    }
                }
            }
            
            // Update the poll message with new vote counts
            $metadata['poll_vote_counts'] = $voteCounts;
            $pollMessage->update(['metadata' => $metadata]);
            
            // Record the vote in the poll_votes table for tracking
            foreach ($votedOptions as $optionIndex) {
                \App\Models\PollVote::updateOrCreate([
                    'message_id' => $pollMessage->id,
                    'user_id' => $user->id,
                    'option_index' => $optionIndex,
                ], [
                    'voted_at' => now(),
                ]);
            }
            
            \Log::info('Poll vote(s) recorded successfully', [
                'poll_message_id' => $pollMessage->id,
                'voter_user_id' => $user->id,
                'poll_name' => $pollData['name'] ?? 'Unknown Poll',
                'voted_options' => $votedOptions,
                'updated_vote_counts' => $voteCounts
            ]);
            
            // Reload the message with poll votes relationship
            $pollMessage->load('pollVotes');
            
            // Broadcast the vote update via WebSocket service
            try {
                $websocketService = app(\App\Services\WebSocketService::class);
                $websocketService->messageUpdated($pollMessage);
            } catch (\Exception $e) {
                \Log::error('Failed to broadcast poll vote update via WebSocket', [
                    'error' => $e->getMessage(),
                ]);
            }
            
            return response()->json([
                'status' => 'success',
                'message' => 'Poll vote(s) processed',
                'data' => [
                    'poll_message_id' => $pollMessage->id,
                    'voter_id' => $user->id,
                    'voted_options' => $votedOptions,
                    'updated_vote_counts' => $voteCounts,
                ]
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error processing poll update', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process poll vote',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Handle message edit notification from receiver (when another user edits a message)
     */
    public function notifyEdit(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'whatsapp_message_id' => 'required|string',
                'content' => 'required|string',
            ]);
            
            $whatsappMessageId = $validated['whatsapp_message_id'];
            $newContent = $validated['content'];
            
            // Find message by WhatsApp message ID
            $message = WhatsAppMessage::where('metadata->message_id', $whatsappMessageId)->first();
            
            if (!$message) {
                \Log::warning('Message not found for edit notification', [
                    'whatsapp_message_id' => $whatsappMessageId,
                ]);
                
                return response()->json([
                    'status' => 'error',
                    'message' => 'Message not found',
                ], 404);
            }
            
            // Store original content
            $originalContent = $message->content;
            
            // Update chat's last message
            $chat = $message->chat;
            if ($chat) {
                // Update contact info if needed
                $this->updateContactInfoIfNeeded($chat, $request->all());
                
                $chat->update([
                    'last_message_id' => $message->id,
                    'last_message_at' => now(),
                ]);

                // Increment unread count for all users except the sender
                if ($senderUser = User::where('phone', $message->sender_phone)->first()) {
                    $chat->users()->where('user_id', '!=', $senderUser->id)->increment('unread_count');
                } else {
                    $chat->increment('unread_count');
                }
            }
            
            // Update message content
            $message->update([
                'content' => $newContent,
                'edited_at' => now(),
            ]);
            
            // Get the user who sent the message (for broadcast)
            $user = User::where('phone', $message->sender_phone)->first() ?? User::getFirstUser();
            
            // Broadcast edit event
            broadcast(new \App\Events\MessageEdited(
                $message,
                $user,
                $originalContent
            ))->toOthers();
            
            \Log::info('Message edit notification processed', [
                'message_id' => $message->id,
                'whatsapp_message_id' => $whatsappMessageId,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Message edit notification processed',
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error processing message edit notification', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process edit notification',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Handle message delete notification from receiver (when another user deletes a message)
     */
    public function notifyDelete(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'whatsapp_message_id' => 'required|string',
            ]);
            
            $whatsappMessageId = $validated['whatsapp_message_id'];
            
            // Find message by WhatsApp message ID
            $message = WhatsAppMessage::where('metadata->message_id', $whatsappMessageId)->first();
            
            if (!$message) {
                \Log::warning('Message not found for delete notification', [
                    'whatsapp_message_id' => $whatsappMessageId,
                ]);
                
                return response()->json([
                    'status' => 'error',
                    'message' => 'Message not found',
                ], 404);
            }
            
            $chatId = $message->chat_id;
            $messageId = $message->id;
            
            // Soft delete the message
            $message->delete();
            
            // Get the user who sent the message (for broadcast)
            $user = User::where('phone', $message->sender_phone)->first() ?? User::getFirstUser();
            
            // Broadcast deletion event
            broadcast(new \App\Events\MessageDeleted(
                $messageId,
                $chatId,
                $user,
                true // forEveryone
            ))->toOthers();
            
            \Log::info('Message delete notification processed', [
                'message_id' => $messageId,
                'whatsapp_message_id' => $whatsappMessageId,
            ]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Message delete notification processed',
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error processing message delete notification', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process delete notification',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Find or create a user for the given phone number - safely
     */
    private function findOrCreateUserSafely(string $phone): User
    {
        // Handle special case where sender is "me" (current authenticated user)
        if ($phone === 'me') {
            $user = auth()->user();
            if (!$user) {
                throw new \InvalidArgumentException("No authenticated user found for sender 'me'");
            }
            return $user;
        }
        
        // First try to find by phone if it exists
        $user = User::where('phone', $phone)->first();
        
        if ($user) {
            return $user;
        }
        
        // Validate phone number before creating user
        \Log::debug('Validating phone number', [
            'phone' => $phone,
            'isValid' => $this->isValidPhoneNumber($phone)
        ]);
        
        if (!$this->isValidPhoneNumber($phone)) {
            \Log::warning('Skipping user creation for invalid phone number', [
                'phone' => $phone,
            ]);
            
            // Return a default user or throw an exception
            throw new \InvalidArgumentException("Invalid phone number: {$phone}");
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
            
            \Log::info('Created new user', [
                'user_id' => $user->id,
                'phone' => $phone,
            ]);
            
            return $user;
        } catch (\Exception $e) {
            \Log::error('Failed to create user', [
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
     * Vote on a poll message
     * 
     * POST /api/messages/{message}/vote
     */
    public function vote(Request $request, WhatsAppMessage $message): JsonResponse
    {
        // Validate this is a poll message
        if ($message->type !== 'poll') {
            return response()->json([
                'error' => 'This message is not a poll'
            ], 400);
        }
        
        // Validate the vote
        $validated = $request->validate([
            'option_index' => 'required|integer|min:0',
        ]);
        
        $optionIndex = $validated['option_index'];
        $user = $request->user();
        
        // Get poll data to validate option index
        $pollData = $message->metadata['poll_data'] ?? [];
        $options = $pollData['options'] ?? [];
        
        if ($optionIndex >= count($options)) {
            return response()->json([
                'error' => 'Invalid option index'
            ], 400);
        }
        
        // Get existing vote counts or initialize
        $voteCounts = $message->metadata['poll_vote_counts'] ?? [];
        
        // Handle both array and object formats
        if (is_array($voteCounts) && array_keys($voteCounts) === range(0, count($voteCounts) - 1)) {
            // Convert array to object with numeric keys
            $result = [];
            foreach ($voteCounts as $index => $count) {
                $result[strval($index)] = $count ?: 0;
            }
            $voteCounts = $result;
        }
        
        // Initialize missing vote counts
        foreach ($options as $index => $option) {
            if (!isset($voteCounts[strval($index)])) {
                $voteCounts[strval($index)] = 0;
            }
        }
        
        // Check if user already voted
        $existingVote = \App\Models\PollVote::where('message_id', $message->id)
            ->where('user_id', $user->id)
            ->first();
        
        if ($existingVote) {
            // User is changing their vote
            $oldIndex = $existingVote->option_index;
            
            // Decrement old vote count
            if (isset($voteCounts[strval($oldIndex)]) && $voteCounts[strval($oldIndex)] > 0) {
                $voteCounts[strval($oldIndex)]--;
            }
            
            // Update the vote
            $existingVote->update([
                'option_index' => $optionIndex,
                'voted_at' => now(),
            ]);
        } else {
            // New vote
            \App\Models\PollVote::create([
                'message_id' => $message->id,
                'user_id' => $user->id,
                'option_index' => $optionIndex,
                'voted_at' => now(),
            ]);
        }
        
        // Increment new vote count
        if (!isset($voteCounts[strval($optionIndex)])) {
            $voteCounts[strval($optionIndex)] = 0;
        }
        $voteCounts[strval($optionIndex)]++;
        
        // Update message metadata with new vote counts
        $metadata = $message->metadata;
        $metadata['poll_vote_counts'] = $voteCounts;
        $message->update(['metadata' => $metadata]);
        
        // Reload the message with poll votes relationship
        $message->load('pollVotes');
        
        // Broadcast the vote update via WebSocket service
        try {
            $websocketService = app(\App\Services\WebSocketService::class);
            $websocketService->messageUpdated($message);
        } catch (\Exception $e) {
            \Log::error('Failed to broadcast poll vote update via WebSocket', [
                'error' => $e->getMessage(),
            ]);
        }
        
        // Send the vote to WhatsApp via receiver
        try {
            $chatJid = $message->chat->metadata['whatsapp_id'] ?? $message->chat;
            $pollMessageId = $message->metadata['message_id'] ?? null;
            
            if ($chatJid && $pollMessageId) {
                $receiverUrl = config('app.receiver_url', env('RECEIVER_URL', 'http://127.0.0.1:3000'));
                $receiverUrl = rtrim($receiverUrl, '/');
                
                $http = \Illuminate\Support\Facades\Http::timeout(10)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'X-API-Key' => config('app.receiver_api_key', ''),
                    ]);
                
                $isHttps = str_starts_with(strtolower($receiverUrl), 'https://');
                $allowInsecure = (bool) env('RECEIVER_TLS_INSECURE', false);
                if ($isHttps && $allowInsecure) {
                    $http = $http->withoutVerifying();
                }
                
                $response = $http->post("{$receiverUrl}/send-poll-vote", [
                    'chatJid' => $chatJid,
                    'pollMessageId' => $pollMessageId,
                    'selectedOptions' => [$optionIndex], // Array of selected option indices
                ]);
                
                if ($response->successful()) {
                    \Log::info('Poll vote sent to WhatsApp successfully', [
                        'message_id' => $message->id,
                        'option_index' => $optionIndex,
                    ]);
                } else {
                    \Log::warning('Failed to send poll vote to WhatsApp', [
                        'message_id' => $message->id,
                        'status' => $response->status(),
                        'response' => $response->body(),
                    ]);
                }
            } else {
                \Log::warning('Cannot send poll vote to WhatsApp: missing chat JID or poll message ID', [
                    'message_id' => $message->id,
                    'has_chat_jid' => !empty($chatJid),
                    'has_poll_message_id' => !empty($pollMessageId),
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('Error sending poll vote to WhatsApp', [
                'error' => $e->getMessage(),
                'message_id' => $message->id,
            ]);
            // Don't fail the request if WhatsApp sending fails
        }
        
        return response()->json([
            'status' => 'success',
            'message' => 'Vote recorded',
            'data' => (new WhatsAppMessageResource($message))->toArray(request())
        ]);
    }

    /**
     * Create or update contact entry with profile information.
     */
    private function createOrUpdateContact($user, array $data): void
    {
        try {
            $appUser = User::getFirstUser();
            if (!$appUser) {
                \Log::warning('Cannot create/update contact: no app user found');
                return;
            }

            // Extract phone number from sender
            $phone = $data['sender'];
            
            // Check if contact already exists
            $existingContact = \App\Models\Contact::where('user_id', $appUser->id)
                ->where('phone', $phone)
                ->first();

            if ($existingContact) {
                // Contact exists - only update profile picture and bio, keep existing name
                $updates = [];
                
                if (!empty($data['senderProfilePictureUrl']) && $existingContact->profile_picture_url !== $data['senderProfilePictureUrl']) {
                    $updates['profile_picture_url'] = $data['senderProfilePictureUrl'];
                }
                if (!empty($data['senderBio']) && $existingContact->bio !== $data['senderBio']) {
                    $updates['bio'] = $data['senderBio'];
                }

                if (!empty($updates)) {
                    $existingContact->update($updates);
                    \Log::info('Updated existing contact profile info', [
                        'contact_id' => $existingContact->id,
                        'phone' => $phone,
                        'name' => $existingContact->name,
                        'updated_fields' => array_keys($updates),
                    ]);
                }
            } else {
                // Contact doesn't exist - create new one with generated name
                // Count existing contacts to generate sequential name
                $contactCount = \App\Models\Contact::where('user_id', $appUser->id)->count();
                $generatedName = 'Unbekannter Benutzer ' . ($contactCount + 1);

                $contactData = [
                    'user_id' => $appUser->id,
                    'phone' => $phone,
                    'name' => $generatedName,
                ];

                // Add profile info if provided
                if (!empty($data['senderProfilePictureUrl'])) {
                    $contactData['profile_picture_url'] = $data['senderProfilePictureUrl'];
                }
                if (!empty($data['senderBio'])) {
                    $contactData['bio'] = $data['senderBio'];
                }

                $contact = \App\Models\Contact::create($contactData);

                \Log::info('Created new contact with profile info', [
                    'contact_id' => $contact->id,
                    'phone' => $phone,
                    'name' => $generatedName,
                    'has_picture' => !empty($data['senderProfilePictureUrl']),
                    'has_bio' => !empty($data['senderBio']),
                ]);
            }
        } catch (\Throwable $e) {
            \Log::error('Failed to create/update contact', [
                'error' => $e->getMessage(),
                'sender' => $data['sender'] ?? null,
            ]);
        }
    }
}