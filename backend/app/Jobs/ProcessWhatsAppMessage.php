<?php

namespace App\Jobs;

use App\DataTransferObjects\WhatsAppMessageData;
use App\Services\WhatsAppMessageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 120;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $backoff = [5, 15, 30];

    /**
     * Delete the job if its models no longer exist.
     *
     * @var bool
     */
    public $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public WhatsAppMessageData $messageData
    ) {
        // Set queue priority based on message type
        // Text messages get higher priority than media messages
        $this->onQueue($this->determineQueue());
    }

    /**
     * Determine which queue to use based on message type
     */
    protected function determineQueue(): string
    {
        return match ($this->messageData->type) {
            'text', 'reaction' => 'high',
            'image', 'audio' => 'default',
            'video', 'document' => 'low',
            default => 'default',
        };
    }

    /**
     * Execute the job.
     */
    public function handle(WhatsAppMessageService $messageService): void
    {
        Log::channel('whatsapp')->info('Processing queued message', [
            'job_id' => $this->job->getJobId(),
            'attempt' => $this->attempts(),
            'queue' => $this->queue,
            'message_type' => $this->messageData->type,
            'sender' => $this->messageData->sender,
            'chat' => $this->messageData->chat,
        ]);

        try {
            // Process the message through the service
            Log::channel('whatsapp')->info('Queue job calling messageService->handle()', [
                'type' => $this->messageData->type,
                'sender' => $this->messageData->sender
            ]);
            
            // Add more detailed logging
            Log::channel('whatsapp')->info('MessageData details', [
                'type' => $this->messageData->type,
                'sender' => $this->messageData->sender,
                'chat' => $this->messageData->chat,
                'messageId' => $this->messageData->messageId,
                'emoji' => $this->messageData->emoji ?? 'null',
                'reactedMessageId' => $this->messageData->reactedMessageId ?? 'null'
            ]);
            
            $messageService->handle($this->messageData);

            Log::channel('whatsapp')->info('Successfully processed queued message', [
                'job_id' => $this->job->getJobId(),
                'message_type' => $this->messageData->type,
            ]);
        } catch (\Exception $e) {
            Log::channel('whatsapp')->error('Error processing queued message', [
                'job_id' => $this->job->getJobId(),
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'message_type' => $this->messageData->type,
                'sender' => $this->messageData->sender,
            ]);

            // Re-throw to let Laravel's queue system handle retries
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::channel('whatsapp')->critical('Job failed after all retry attempts', [
            'job_id' => $this->job?->getJobId(),
            'message_type' => $this->messageData->type,
            'sender' => $this->messageData->sender,
            'chat' => $this->messageData->chat,
            'message_id' => $this->messageData->messageId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // You could send a notification here, log to external service, etc.
        // For now, we just log it
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'whatsapp',
            'message:' . $this->messageData->type,
            'chat:' . substr($this->messageData->chat, 0, 20),
        ];
    }
}
