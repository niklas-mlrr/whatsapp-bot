<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class WhatsAppMessageApiTest extends TestCase
{
    use RefreshDatabase;
    /**
     * A basic unit test example.
     */
    public function test_messages_index_returns_paginated_response(): void
    {
        // Arrange: create some messages
        \App\Models\WhatsAppMessage::factory()->count(3)->create();

        // Act: call the API
        $response = $this->getJson('/api/messages');

        // Assert: check structure
        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id', 'sender', 'chat', 'type', 'content', 'sending_time', 'created_at'
                    ]
                ],
                'links', 'meta'
            ]);
    }

}
