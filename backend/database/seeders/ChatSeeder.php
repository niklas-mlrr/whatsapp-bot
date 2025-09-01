<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Chat;

class ChatSeeder extends Seeder
{
    public function run(): void
    {
        // Stelle sicher, dass mindestens ein Benutzer existiert
        $user = User::first();
        if (!$user) {
            $user = User::create([
                'name' => 'Test User',
                'password' => bcrypt('password'),
                'phone' => '+491234567890',
                'status' => 'online'
            ]);
        }

        // Erstelle einen Test-Chat
        $chat = Chat::create([
            'name' => 'Test Chat',
            'type' => 'private',
            'is_group' => false,
            'created_by' => $user->id
        ]);

        // Verknüpfe den Benutzer mit dem Chat
        DB::table('chat_user')->insert([
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'role' => 'member',
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }
}
