<?php

namespace App\DataTransferObjects;

use App\Http\Requests\WhatsAppMessageRequest;

class WhatsAppMessageData
{
    public function __construct(
        public readonly string $sender,
        public readonly string $chat,
        public readonly string $type,
        public readonly ?string $content,
        public readonly ?string $sending_time,
        public readonly ?string $media = null,
        public readonly ?string $mimetype = null,
        public readonly ?array $contextInfo = null,
        public readonly ?string $messageId = null,
        public readonly ?bool $isGroup = false,
        public readonly ?string $fileName = null,
        public readonly ?int $mediaSize = null,
        public readonly ?string $reactedMessageId = null,
        public readonly ?string $emoji = null,
        public readonly ?string $senderJid = null,
        public ?int $sender_id = null,
        public ?int $chat_id = null
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
            senderJid: $validated['senderJid'] ?? null
        );
    }
}
