<?php

namespace Database\Seeders;

use App\Models\Chat;
use App\Models\User;
use Illuminate\Database\Seeder;

class ChatSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get the test user
        $user = User::where('name', 'Test User')->first();
        
        if (!$user) {
            $user = User::factory()->create([
                'name' => 'Test User',
            ]);
        }

        // Create some test chats
        $chats = [
            [
                'name' => 'General Chat',
                'is_group' => true,
                'created_by' => $user->id,
            ],
            [
                'name' => 'Support Chat',
                'is_group' => false,
                'created_by' => $user->id,
            ],
            [
                'name' => 'Project Discussion',
                'is_group' => true,
                'created_by' => $user->id,
            ],
        ];

        foreach ($chats as $chatData) {
            $chat = Chat::create($chatData);
            
            // Add the user to the chat
            $chat->users()->attach($user->id, [
                'role' => 'admin',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
