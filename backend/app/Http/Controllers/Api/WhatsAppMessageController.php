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
            'type' => 'nullable|string|in:text,image,video,audio,document,location,contact,unknown',
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

        $query = WhatsAppMessage::query();

        // Apply filters
        if ($request->filled('chat')) {
            $query->where('chat', $validated['chat']);
        }
        
        if ($request->filled('sender')) {
            $query->where('sender', $validated['sender']);
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
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = $validated['per_page'] ?? 20;
        $messages = $query->paginate($perPage);

        return WhatsAppMessageResource::collection($messages);
    }

    // GET /api/messages/{id}
    public function show($id): JsonResponse
    {
        $message = WhatsAppMessage::findOrFail($id);
        return (new WhatsAppMessageResource($message))->response();
    }

    // DELETE /api/messages/{id}
    public function destroy($id): JsonResponse
    {
        $message = WhatsAppMessage::findOrFail($id);
        $message->delete();
        return response()->json(['status' => 'success', 'message' => 'Message deleted']);
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
            // Reaction-specific fields
            'reactedMessageId' => 'nullable|string',
            'emoji' => 'nullable|string',
            'senderJid' => 'nullable|string',
        ]);
        
        // Handle reaction messages separately
        if ($data['type'] === 'reaction') {
            return $this->handleReaction($data);
        }
        if (empty($data['sending_time'])) {
            $data['sending_time'] = now();
        }
        // Resolve sender and chat to IDs required by whatsapp_messages schema
        // 1) Resolve or create user by phone
        $user = User::firstOrCreate(
            ['phone' => $data['sender']],
            [
                'name' => 'WhatsApp User',
                'password' => bcrypt(Str::random(32)),
                'status' => 'offline',
            ]
        );

        // 2) Resolve or create chat by WhatsApp JID stored in metadata->whatsapp_id
        $chat = Chat::where('metadata->whatsapp_id', $data['chat'])->first();
        if (!$chat) {
            $chat = Chat::create([
                'name' => $data['chat'],
                'is_group' => false,
                'created_by' => $user->id,
                'metadata' => [
                    'whatsapp_id' => $data['chat'],
                    'created_by' => $user->id,
                ],
            ]);
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
        
        \Log::info('Message metadata prepared', [
            'metadata' => $metadata,
            'has_filename' => !empty($data['filename']),
            'filename_value' => $data['filename'] ?? 'not set'
        ]);

        // Send to receiver
        try {
            $receiverUrl = env('RECEIVER_URL');
            
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
            
            // If we get here, the message was sent successfully
            $message = WhatsAppMessage::create([
                'sender_id' => $user->id,
                'chat_id' => $chat->id,
                'type' => $data['type'],
                'status' => 'sent',
                'content' => $data['content'] ?? '',
                'media_url' => $mediaUrl,
                'media_type' => $data['mimetype'] ?? null,
                'metadata' => $metadata,
            ]);
            
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
            $query = WhatsAppMessage::select([
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
            
            // Get or create user
            $senderPhone = $data['senderJid'] ?? $data['sender'];
            $user = User::firstOrCreate(
                ['phone' => $senderPhone],
                [
                    'name' => 'WhatsApp User',
                    'password' => bcrypt(Str::random(32)),
                    'status' => 'offline',
                ]
            );
            
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
}