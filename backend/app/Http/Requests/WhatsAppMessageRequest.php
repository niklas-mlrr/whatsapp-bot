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
        // Authorization is now handled by VerifyWebhookSecret middleware
        // This ensures the request comes from the authenticated receiver service
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
            'messageTimestamp' => ['sometimes', 'integer'], // Unix timestamp from WhatsApp
            'fileName' => ['nullable', 'string'],
            'mediaSize' => ['nullable', 'integer'],
            'duration' => ['nullable', 'integer'],
            'reactedMessageId' => ['nullable', 'string'],
            'emoji' => ['nullable', 'string'],
            'senderJid' => ['nullable', 'string'],
            'quotedMessage' => ['nullable', 'array'],
            'quotedMessage.quotedMessageId' => ['nullable', 'string'],
            'quotedMessage.quotedContent' => ['nullable', 'string'],
            'quotedMessage.quotedSender' => ['nullable', 'string'],
            // Sender profile info (for contact info updates)
            'senderProfilePictureUrl' => ['nullable', 'url'],
            'senderBio' => ['nullable', 'string', 'max:500'],
            // Poll data
            'pollData' => ['nullable', 'array'],
            'pollData.name' => ['nullable', 'string', 'max:255'],
            'pollData.options' => ['nullable', 'array', 'min:1', 'max:12'],
            'pollData.options.*.optionName' => ['nullable', 'string', 'max:100'],
            'pollData.options.*.name' => ['nullable', 'string', 'max:100'],
            'pollData.pollType' => ['nullable', 'string', 'in:POLL'],
            'pollData.pollContentType' => ['nullable', 'string', 'in:TEXT'],
            'pollData.selectableOptionsCount' => ['nullable', 'integer', 'min:0'],
            // Poll update data
            'pollMessageId' => ['nullable', 'string'],
            'selectedOptionIndex' => ['nullable', 'integer'],
            'selectedOptions' => ['nullable', 'array'],
            'selectedOptions.*' => ['integer'],
        ];

        // Add required validation for either 'from' or 'sender'
        // Convert messageTimestamp (unix timestamp) to sending_time if present
        $sendingTime = $this->input('sending_time') 
            ?? $this->input('timestamp') 
            ?? ($this->input('messageTimestamp') ? date('Y-m-d H:i:s', $this->input('messageTimestamp')) : null)
            ?? now()->toDateTimeString();
            
        $this->mergeIfMissing([
            'from' => $this->input('sender'),
            'sender' => $this->input('from'),
            'sending_time' => $sendingTime,
            'content' => $this->input('content') ?? $this->input('body'),
        ]);

        return $rules;
    }
    
    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        \Log::channel('whatsapp')->error('Validation failed for webhook request', [
            'errors' => $validator->errors()->all(),
            'input' => $this->all(),
        ]);
        
        throw new \Illuminate\Validation\ValidationException($validator);
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Sanitize string inputs to prevent XSS
        $sanitizedData = [];
        
        if ($this->has('content')) {
            $sanitizedData['content'] = \App\Helpers\SecurityHelper::sanitizeString($this->input('content'));
        }
        
        if ($this->has('body')) {
            $sanitizedData['body'] = \App\Helpers\SecurityHelper::sanitizeString($this->input('body'));
        }
        
        if ($this->has('sender')) {
            $sanitizedData['sender'] = \App\Helpers\SecurityHelper::sanitizeJid($this->input('sender'));
        }
        
        if ($this->has('from')) {
            $sanitizedData['from'] = \App\Helpers\SecurityHelper::sanitizeJid($this->input('from'));
        }
        
        if ($this->has('chat')) {
            $sanitizedData['chat'] = \App\Helpers\SecurityHelper::sanitizeJid($this->input('chat'));
        }
        
        if ($this->has('senderJid')) {
            $sanitizedData['senderJid'] = \App\Helpers\SecurityHelper::sanitizeJid($this->input('senderJid'));
        }
        
        if ($this->has('fileName')) {
            $sanitizedData['fileName'] = \App\Helpers\SecurityHelper::sanitizeFilename($this->input('fileName'));
        }
        
        $this->merge($sanitizedData);
    }
}
