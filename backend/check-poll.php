<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

$kernel->bootstrap();

// Get the latest poll message
$msg = \App\Models\WhatsAppMessage::where('type', 'poll')->latest()->first();

if ($msg) {
    echo "Message ID: " . $msg->id . PHP_EOL;
    echo "Content: " . $msg->content . PHP_EOL;
    echo "Type: " . $msg->type . PHP_EOL;
    echo "Metadata: " . json_encode($msg->metadata, JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    echo "No poll messages found" . PHP_EOL;
}
