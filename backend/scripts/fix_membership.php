<?php
// backend/scripts/fix_membership.php
// Usage: php backend/scripts/fix_membership.php <chatId> <userId>
// Ensures a membership row exists in chat_user for the given chat and user.

use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$chatId = $argv[1] ?? null;
$userId = $argv[2] ?? null;

if (!$chatId || !$userId) {
    fwrite(STDERR, "Usage: php backend/scripts/fix_membership.php <chatId> <userId>\n");
    exit(1);
}

// Validate chat and user exist
$chat = DB::select('SELECT id, name FROM chats WHERE id = ? LIMIT 1', [$chatId]);
$user = DB::select('SELECT id, name FROM users WHERE id = ? LIMIT 1', [$userId]);

if (empty($chat)) {
    fwrite(STDERR, "Chat not found: $chatId\n");
    exit(1);
}
if (empty($user)) {
    fwrite(STDERR, "User not found: $userId\n");
    exit(1);
}

$exists = DB::select('SELECT 1 FROM chat_user WHERE chat_id = ? AND user_id = ? LIMIT 1', [$chatId, $userId]);
if (!empty($exists)) {
    echo "Membership already exists for chat $chatId and user $userId\n";
    exit(0);
}

DB::insert('INSERT INTO chat_user (chat_id, user_id, created_at, updated_at) VALUES (?, ?, NOW(), NOW())', [$chatId, $userId]);

echo "Membership added: chat_id=$chatId user_id=$userId\n";
