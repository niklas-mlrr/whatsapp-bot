<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ChatService $chatService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chatService = app(ChatService::class);
    }

    /** @test */
    public function it_creates_a_direct_chat_between_two_users()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $chat = $this->chatService->createDirectChat($user1->id, $user2->id);

        $this->assertInstanceOf(Chat::class, $chat);
        $this->assertFalse($chat->is_group);
        $this->assertCount(2, $chat->participants);
        $this->assertContains($user1->phone, $chat->participants);
        $this->assertContains($user2->phone, $chat->participants);
        $this->assertCount(2, $chat->users);
    }

    /** @test */
    public function it_returns_existing_direct_chat_if_one_exists()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        // Create a direct chat between the users
        $firstChat = $this->chatService->createDirectChat($user1->id, $user2->id);
        
        // Try to create another direct chat between the same users
        $secondChat = $this->chatService->createDirectChat($user1->id, $user2->id);

        $this->assertEquals($firstChat->id, $secondChat->id);
        $this->assertCount(1, Chat::where('is_group', false)->get());
    }

    /** @test */
    public function it_creates_a_group_chat()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $user3 = User::factory()->create();

        $chat = $this->chatService->createGroupChat(
            'Test Group',
            [$user1->id, $user2->id, $user3->id],
            $user1->id
        );

        $this->assertInstanceOf(Chat::class, $chat);
        $this->assertTrue($chat->is_group);
        $this->assertEquals('Test Group', $chat->name);
        $this->assertCount(3, $chat->participants);
        $this->assertCount(3, $chat->users);
    }

    /** @test */
    public function it_adds_participants_to_a_group_chat()
    {
        $users = User::factory()->count(4)->create();
        $chat = $this->chatService->createGroupChat(
            'Test Group',
            [$users[0]->id, $users[1]->id],
            $users[0]->id
        );

        $this->chatService->addParticipants($chat->id, [$users[2]->id, $users[3]->id]);
        $chat->refresh();

        $this->assertCount(4, $chat->participants);
        $this->assertCount(4, $chat->users);
    }

    /** @test */
    public function it_removes_participants_from_a_group_chat()
    {
        $users = User::factory()->count(3)->create();
        $chat = $this->chatService->createGroupChat(
            'Test Group',
            [$users[0]->id, $users[1]->id, $users[2]->id],
            $users[0]->id
        );

        $this->chatService->removeParticipants($chat->id, [$users[1]->id]);
        $chat->refresh();

        $this->assertCount(2, $chat->participants);
        $this->assertCount(2, $chat->users);
        $this->assertNotContains($users[1]->phone, $chat->participants);
    }

    /** @test */
    public function it_deletes_chat_when_last_participant_leaves()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $chat = $this->chatService->createDirectChat($user1->id, $user2->id);

        // First user leaves
        $this->chatService->removeParticipants($chat->id, [$user1->id]);
        
        // Chat should still exist with one participant
        $this->assertDatabaseHas('chats', ['id' => $chat->id]);
        
        // Second user leaves
        $this->chatService->removeParticipants($chat->id, [$user2->id]);
        
        // Chat should be deleted
        $this->assertDatabaseMissing('chats', ['id' => $chat->id]);
    }

    /** @test */
    public function it_assigns_new_admin_when_last_admin_leaves()
    {
        $users = User::factory()->count(3)->create();
        $chat = $this->chatService->createGroupChat(
            'Test Group',
            [$users[0]->id, $users[1]->id, $users[2]->id],
            $users[0]->id
        );

        // Make the second user an admin
        $chat->users()->updateExistingPivot($users[1]->id, ['role' => 'admin']);
        
        // First user (admin) leaves
        $this->chatService->removeParticipants($chat->id, [$users[0]->id]);
        $chat->refresh();

        // Second user should now be the only admin
        $adminIds = $chat->users()
            ->wherePivot('role', 'admin')
            ->pluck('users.id')
            ->toArray();

        $this->assertEquals([$users[1]->id], $adminIds);
    }

    /** @test */
    public function it_toggles_mute_for_a_user()
    {
        $user = User::factory()->create();
        $chat = $this->chatService->createGroupChat(
            'Test Group',
            [$user->id],
            $user->id
        );

        // Mute for 1 hour
        $this->chatService->toggleMute($chat->id, $user->id, true, 60);
        
        $pivot = $chat->users()->find($user->id)->pivot;
        $this->assertNotNull($pivot->muted_until);
        $this->assertTrue($pivot->muted_until->isFuture());

        // Unmute
        $this->chatService->toggleMute($chat->id, $user->id, false);
        
        $pivot->refresh();
        $this->assertNull($pivot->muted_until);
    }
}
