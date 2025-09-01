<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

/** @var \Illuminate\Contracts\Container\Container $app */

try {
    $operator = \App\Models\User::getFirstUser();
    if (!$operator) {
        echo "No operator user found via User::getFirstUser(). Aborting.\n";
        exit(1);
    }

    $operatorId = (int)$operator->id;
    $attached = 0;
    $already = 0;

    $chats = \App\Models\Chat::query()->get(['id', 'name']);
    foreach ($chats as $chat) {
        $exists = DB::table('chat_user')
            ->where('chat_id', $chat->id)
            ->where('user_id', $operatorId)
            ->exists();

        if ($exists) {
            $already++;
            continue;
        }

        DB::table('chat_user')->insert([
            'chat_id'   => $chat->id,
            'user_id'   => $operatorId,
            'role'      => 'member',
            'joined_at' => now(),
            'left_at'   => null,
            'created_at'=> now(),
            'updated_at'=> now(),
        ]);

        echo sprintf("Attached operator (id=%d) to chat id=%d (%s)\n", $operatorId, $chat->id, $chat->name ?? '');
        $attached++;
    }

    echo "\nSummary:\n";
    echo " - Operator user id: {$operatorId}\n";
    echo " - Chats processed: " . $chats->count() . "\n";
    echo " - Already members: {$already}\n";
    echo " - Newly attached: {$attached}\n";

    exit(0);
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
