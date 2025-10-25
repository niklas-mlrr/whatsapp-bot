<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Http\Controllers\Api\WhatsAppMessageController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

class WhatsAppMessageControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_findOrCreateUserSafely_handles_me_sender(): void
    {
        // Arrange: create a user and authenticate
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        // Create controller instance
        $controller = new WhatsAppMessageController();

        // Use reflection to access the private method
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('findOrCreateUserSafely');
        $method->setAccessible(true);

        // Act: call the method with "me" as sender
        $result = $method->invoke($controller, 'me');

        // Assert: should return the authenticated user
        $this->assertEquals($user->id, $result->id);
        $this->assertEquals($user->name, $result->name);
        $this->assertEquals($user->phone, $result->phone);
    }

    public function test_findOrCreateUserSafely_throws_exception_when_me_but_no_auth(): void
    {
        // Arrange: no authentication
        $controller = new WhatsAppMessageController();

        // Use reflection to access the private method
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('findOrCreateUserSafely');
        $method->setAccessible(true);

        // Act & Assert: should throw exception when no authenticated user
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("No authenticated user found for sender 'me'");
        
        $method->invoke($controller, 'me');
    }

    public function test_findOrCreateUserSafely_handles_valid_phone_number(): void
    {
        // Arrange: create a user with a phone number
        $user = User::factory()->create(['phone' => '1234567890']);
        $this->actingAs($user, 'sanctum');

        $controller = new WhatsAppMessageController();

        // Use reflection to access the private method
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('findOrCreateUserSafely');
        $method->setAccessible(true);

        // Act: call the method with a valid phone number
        $result = $method->invoke($controller, '1234567890');

        // Assert: should return the user with that phone number
        $this->assertEquals($user->id, $result->id);
        $this->assertEquals('1234567890', $result->phone);
    }
}
