<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$message = \App\Models\WhatsAppMessage::find(103);
$resource = new \App\Http\Resources\WhatsAppMessageResource($message);
$array = $resource->toArray(request());

echo "Filename in array: " . ($array['filename'] ?? 'NOT SET') . PHP_EOL;
echo "Size in array: " . ($array['size'] ?? 'NOT SET') . PHP_EOL;
echo "Metadata filename: " . ($message->metadata['filename'] ?? 'NOT SET') . PHP_EOL;
echo "Metadata file_size: " . ($message->metadata['file_size'] ?? 'NOT SET') . PHP_EOL;
