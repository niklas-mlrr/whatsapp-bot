<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;


class WhatsAppMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Hier könnte man z.B. die IP-Adresse des Absenders prüfen.
        // Fürs Erste erlauben wir alle Anfragen.
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Log the incoming request data for debugging
        \Log::channel('whatsapp')->debug('Incoming webhook request', [
            'headers' => $this->headers->all(),
            'input' => $this->all(),
            'ip' => $this->ip(),
        ]);

        // Prepare the rules
        $rules = [
            'type' => ['required', 'string', 'max:50'],
            'body' => ['nullable', 'string'],
            'content' => ['nullable', 'string'],
            'sender' => ['sometimes', 'string', 'max:255'],
            'from' => ['sometimes', 'string', 'max:255'],
            'chat' => ['sometimes', 'string', 'max:255'],
            'sending_time' => ['nullable', 'date'],
            'timestamp' => ['nullable', 'date'],
            'media' => ['nullable', 'string'],
            'mimetype' => ['nullable', 'string'],
            'contextInfo' => ['sometimes', 'array'],
            'messageId' => ['nullable', 'string'],
            'isGroup' => ['sometimes', 'boolean'],
            'messageTimestamp' => ['sometimes', 'string'],
            'fileName' => ['nullable', 'string'],
            'mediaSize' => ['nullable', 'integer'],
            'reactedMessageId' => ['nullable', 'string'],
            'emoji' => ['nullable', 'string'],
            'senderJid' => ['nullable', 'string'],
        ];

        // Add required validation for either 'from' or 'sender'
        $this->mergeIfMissing([
            'from' => $this->input('sender'),
            'sender' => $this->input('from'),
            'sending_time' => $this->input('sending_time') ?? $this->input('timestamp') ?? now()->toDateTimeString(),
            'content' => $this->input('content') ?? $this->input('body'),
        ]);

        return $rules;
    }
}
