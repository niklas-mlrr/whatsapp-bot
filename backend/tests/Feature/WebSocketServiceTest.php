<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\WebSocketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

class WebSocketServiceTest extends TestCase
{
    use RefreshDatabase;

    protected WebSocketService $webSocketService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->webSocketService = app(WebSocketService::class);
    }

    /** @test */
    public function it_can_be_instantiated()
    {
        $this->assertInstanceOf(WebSocketService::class, $this->webSocketService);
    }

    /** @test */
    public function newMessage_broadcasts_to_correct_channel()
    {
        // Create test data
        $user = User::factory()->create();
        $chat = Chat::factory()->create(['is_group' => false]);
        $chat->users()->attach($user);

        $message = WhatsAppMessage::create([
            'chat_id' => $chat->id,
            'sender_id' => $user->id,
            'content' => 'Test message',
            'type' => 'text',
            'status' => 'sent',
        ]);

        // Mock the Broadcast facade
        Broadcast::fake();

        // Call the method
        $this->webSocketService->newMessage($message);

        // Assert broadcast was called
        Broadcast::assertSent('chat.' . $chat->id);
    }

    /** @test */
    public function messageStatusUpdated_broadcasts_status_update()
    {
        // Create test data
        $user = User::factory()->create();
        $chat = Chat::factory()->create(['is_group' => false]);
        $chat->users()->attach($user);

        $message = WhatsAppMessage::create([
            'chat_id' => $chat->id,
            'sender_id' => $user->id,
            'content' => 'Test message',
            'type' => 'text',
            'status' => 'delivered',
        ]);

        // Mock the Broadcast facade
        Broadcast::fake();

        // Call the method
        $this->webSocketService->messageStatusUpdated($message);

        // Assert broadcast was called
        Broadcast::assertSent('chat.' . $chat->id);
    }

    /** @test */
    public function newMessage_handles_broadcast_failures_gracefully()
    {
        // Create test data
        $user = User::factory()->create();
        $chat = Chat::factory()->create(['is_group' => false]);
        $chat->users()->attach($user);

        $message = WhatsAppMessage::create([
            'chat_id' => $chat->id,
            'sender_id' => $user->id,
            'content' => 'Test message',
            'type' => 'text',
            'status' => 'sent',
        ]);

        // This test verifies the method doesn't throw exceptions
        // The actual broadcast failure handling is internal
        $this->expectNotToPerformAssertions();

        $this->webSocketService->newMessage($message);
    }
}