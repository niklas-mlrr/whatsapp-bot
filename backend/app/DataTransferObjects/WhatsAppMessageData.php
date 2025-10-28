<?php

namespace App\DataTransferObjects;

use App\Http\Requests\WhatsAppMessageRequest;

class WhatsAppMessageData
{
    public function __construct(
        public string $sender,
        public string $chat,
        public string $type,
        public ?string $content,
        public ?string $sending_time,
        public ?string $media = null,
        public ?string $mimetype = null,
        public ?array $contextInfo = null,
        public ?string $messageId = null,
        public ?bool $isGroup = false,
        public ?string $fileName = null,
        public ?int $mediaSize = null,
        public ?string $reactedMessageId = null,
        public ?string $emoji = null,
        public ?string $senderJid = null,
        public ?array $quotedMessage = null,
        // Contact info from receiver (optional)
        public ?string $senderProfilePictureUrl = null,
        public ?string $senderBio = null,
        public ?int $sender_id = null,
        public ?int $chat_id = null,
        // Poll data (optional)
        public ?array $pollData = null,
        // Poll update data
        public ?string $pollMessageId = null
    ) {
    }

    public static function fromRequest(WhatsAppMessageRequest $request): self
    {
        $validated = $request->validated();
        
        // Get sender (either from 'sender' or 'from' field)
        $sender = $validated['sender'] ?? $validated['from'] ?? null;
        
        // Get chat (either from 'chat' or 'from' field)
        $chat = $validated['chat'] ?? $validated['from'] ?? $sender;
        
        // Get content (either from 'content' or 'body' field)
        $content = $validated['content'] ?? $validated['body'] ?? '';
        
        // Get timestamp (either from 'sending_time' or 'timestamp' or current time)
        $sendingTime = $validated['sending_time'] ?? $validated['timestamp'] ?? now()->toDateTimeString();
        
        // If we still don't have a sender or chat, throw an exception
        if (!$sender || !$chat) {
            throw new \InvalidArgumentException(sprintf(
                'Missing required fields. Sender: %s, Chat: %s',
                $sender ? 'present' : 'missing',
                $chat ? 'present' : 'missing'
            ));
        }
        
        return new self(
            sender: $sender,
            chat: $chat,
            type: $validated['type'] ?? 'text',
            content: $content,
            sending_time: $sendingTime,
            media: $validated['media'] ?? null,
            mimetype: $validated['mimetype'] ?? null,
            contextInfo: $validated['contextInfo'] ?? null,
            messageId: $validated['messageId'] ?? $validated['id'] ?? null,
            isGroup: (bool)($validated['isGroup'] ?? false),
            fileName: $validated['fileName'] ?? null,
            mediaSize: $validated['mediaSize'] ?? null,
            reactedMessageId: $validated['reactedMessageId'] ?? null,
            emoji: $validated['emoji'] ?? null,
            senderJid: $validated['senderJid'] ?? null,
            quotedMessage: $validated['quotedMessage'] ?? null,
            senderProfilePictureUrl: $validated['senderProfilePictureUrl'] ?? null,
            senderBio: $validated['senderBio'] ?? null,
            pollData: $validated['pollData'] ?? null,
            pollMessageId: $validated['pollMessageId'] ?? null
        );
    }
}
