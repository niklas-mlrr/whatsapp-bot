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
        ]);
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
            // If it's already a full URL, keep as-is; otherwise store relative path
            $mediaUrl = filter_var($data['media'], FILTER_VALIDATE_URL)
                ? $data['media']
                : $data['media'];
        }

        $message = WhatsAppMessage::create([
            'sender_id' => $user->id,
            'chat_id' => $chat->id,
            'type' => $data['type'],
            'status' => 'sent',
            'content' => $data['content'] ?? '',
            'media_url' => $mediaUrl,
            'media_type' => $data['mimetype'] ?? null,
            'metadata' => [
                'sender' => $data['sender'],
                'chat' => $data['chat'],
                'sending_time' => (string) $data['sending_time'],
            ],
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

            // If this is an image message, include the full path to the media
            if ($data['type'] === 'image' && !empty($data['media'])) {
                \Log::info('Processing image message', [
                    'media' => $data['media'],
                    'exists' => Storage::exists($data['media'])
                ]);
                
                // If media is a URL, use it directly
                if (filter_var($data['media'], FILTER_VALIDATE_URL)) {
                    $sendPayload['media'] = $data['media'];
                } 
                // If media is a path, read the file and send as base64
                else if (Storage::exists($data['media'])) {
                    // Get the correct path to the file
                    $filePath = Storage::path($data['media']);
                    
                    \Log::info('Attempting to read file', [
                        'storage_path' => $filePath,
                        'relative_path' => $data['media']
                    ]);
                    
                    if (file_exists($filePath)) {
                        $fileContents = file_get_contents($filePath);
                        $base64 = base64_encode($fileContents);
                        $sendPayload['media'] = 'data:' . ($data['mimetype'] ?? 'image/jpeg') . ';base64,' . $base64;
                        \Log::info('Converted local file to base64', [
                            'size' => strlen($base64) . ' bytes',
                            'file' => basename($filePath)
                        ]);
                    } else {
                        \Log::error('File does not exist at path', ['path' => $filePath]);
                        throw new \Exception('File does not exist at path: ' . $filePath);
                    }
                }
                
                // Ensure we have a valid media URL
                if (empty($sendPayload['media'])) {
                    return response()->json([
                        'status' => 'error', 
                        'message' => 'Invalid media file',
                        'media_path' => $data['media'],
                        'storage_exists' => Storage::exists($data['media'])
                    ], 400);
                }
                
                \Log::info('Processed media URL', ['url' => $sendPayload['media']]);
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

            $response = $http->post($receiverUrl, $sendPayload);
            
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
            
            // If we get here, the message was sent successfully
            $message = WhatsAppMessage::findOrFail($message->id);
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
                'file' => 'required|file|image|max:10240', // max 10MB
            ]);
            
            // Ensure the uploads directory exists
            $path = $request->file('file')->store('uploads', 'public');
            
            // Get the full URL to the uploaded file
            $url = url(Storage::url($path));
            
            // Make sure the URL is absolute
            if (strpos($url, 'http') !== 0) {
                $url = url($url);
            }
            
            return response()->json([
                'status' => 'success',
                'path' => $path,
                'url' => $url,
                'mimetype' => $request->file('file')->getMimeType()
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
}