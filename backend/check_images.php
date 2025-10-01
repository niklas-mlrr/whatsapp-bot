<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$messages = DB::table('whatsapp_messages')
    ->where('type', 'image')
    ->select('id', 'media_url', 'media_type', 'content', 'created_at')
    ->orderBy('id', 'desc')
    ->limit(10)
    ->get();

echo "Image messages in database:\n";
echo str_repeat('=', 80) . "\n";

foreach ($messages as $msg) {
    echo "ID: {$msg->id}\n";
    echo "Content: " . ($msg->content ?: '(empty)') . "\n";
    echo "Media URL: " . ($msg->media_url ?: 'NULL') . "\n";
    echo "Media Type: " . ($msg->media_type ?: 'NULL') . "\n";
    echo "Created: {$msg->created_at}\n";
    echo str_repeat('-', 80) . "\n";
}

echo "\nTotal image messages found: " . $messages->count() . "\n";
