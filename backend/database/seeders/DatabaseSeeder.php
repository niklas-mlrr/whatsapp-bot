<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Chat;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Ensure a few baseline users exist
        $users = [
            ['name' => 'Test User', 'phone' => '+10000000001'],
            ['name' => 'Alice Johnson', 'phone' => '+10000000002'],
            ['name' => 'Bob Smith', 'phone' => '+10000000003'],
        ];

        foreach ($users as $u) {
            User::firstOrCreate(
                ['name' => $u['name']],
                [
                    'phone' => $u['phone'],
                    'password' => Hash::make('password'),
                ]
            );
        }

        // Ensure an Admin user with known credentials exists
        User::firstOrCreate(
            ['name' => 'Admin'],
            [
                'phone' => '+10000000000',
                'password' => Hash::make('admin123'),
            ]
        );

        // Create test chats
        $testUser = User::where('name', 'Test User')->first();
        $alice = User::where('name', 'Alice Johnson')->first();
        $bob = User::where('name', 'Bob Smith')->first();

        // Create a private chat between Test User and Alice
        $privateChat = Chat::create([
            'name' => 'Private Chat',
            'type' => 'private',
            'is_group' => false,
            'created_by' => $testUser->id
        ]);

        // Create a group chat with all users
        $groupChat = Chat::create([
            'name' => 'Group Chat',
            'type' => 'group',
            'is_group' => true,
            'created_by' => $testUser->id
        ]);

        // Add users to chats
        $chats = [
            ['chat' => $privateChat, 'users' => [$testUser, $alice]],
            ['chat' => $groupChat, 'users' => [$testUser, $alice, $bob]]
        ];

        foreach ($chats as $chatData) {
            foreach ($chatData['users'] as $user) {
                DB::table('chat_user')->insertOrIgnore([
                    'chat_id' => $chatData['chat']->id,
                    'user_id' => $user->id,
                    'role' => $user->id === $testUser->id ? 'admin' : 'member',
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }
        }
    }
}
