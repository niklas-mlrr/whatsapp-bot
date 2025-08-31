<?php

use App\Models\WhatsAppMessage;
use App\Models\Chat;
use App\Models\User;

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Check latest message
try {
    $message = WhatsAppMessage::latest()->first(['id', 'content', 'sender_id', 'chat_id', 'created_at']);
    echo "Latest Message:\nID: ".($message->id ?? 'None')."\n";
    echo "Content: ".($message->content ?? 'None')."\n";
    echo "Sender ID: ".($message->sender_id ?? 'None')."\n";
    echo "Chat ID: ".($message->chat_id ?? 'None')."\n";
    echo "Created At: ".($message->created_at ?? 'None')."\n\n";
} catch (\Exception $e) {
    echo "Error checking messages: ".$e->getMessage()."\n\n";
}

// Check latest chat
try {
    $chat = Chat::latest()->first(['id', 'name', 'is_group', 'created_by', 'created_at']);
    echo "Latest Chat:\nID: ".($chat->id ?? 'None')."\n";
    echo "Name: ".($chat->name ?? 'None')."\n";
    echo "Is Group: ".($chat->is_group ? 'Yes' : 'No')."\n";
    echo "Created By: ".($chat->created_by ?? 'None')."\n";
    echo "Created At: ".($chat->created_at ?? 'None')."\n\n";
} catch (\Exception $e) {
    echo "Error checking chats: ".$e->getMessage()."\n\n";
}

// Check user
try {
    $user = User::where('phone', '4917646765869')->first(['id', 'name', 'phone', 'email', 'created_at']);
    echo "User:\nID: ".($user->id ?? 'None')."\n";
    echo "Name: ".($user->name ?? 'None')."\n";
    echo "Phone: ".($user->phone ?? 'None')."\n";
    echo "Email: ".($user->email ?? 'None')."\n";
    echo "Created At: ".($user->created_at ?? 'None')."\n";
} catch (\Exception $e) {
    echo "Error checking user: ".$e->getMessage()."\n";
}
