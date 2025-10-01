<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

// Simulate an API request
$request = Illuminate\Http\Request::create('/api/chats/3/messages/latest?limit=5', 'GET');
$request->headers->set('Accept', 'application/json');

// Get a user to authenticate
$user = App\Models\User::find(1);
if ($user) {
    $request->setUserResolver(function () use ($user) {
        return $user;
    });
}

try {
    $controller = new App\Http\Controllers\Api\ChatController();
    $response = $controller->latestMessages(3, $request);
    
    $data = json_decode($response->getContent(), true);
    
    echo "API Response for latest messages:\n";
    echo str_repeat('=', 80) . "\n";
    
    if (isset($data['data'])) {
        echo "Total messages: " . count($data['data']) . "\n\n";
        
        foreach (array_slice($data['data'], -5) as $msg) {
            echo "ID: " . $msg['id'] . "\n";
            echo "Type: " . ($msg['type'] ?? 'N/A') . "\n";
            echo "Content: " . (isset($msg['content']) ? substr($msg['content'], 0, 30) : 'N/A') . "\n";
            echo "Media: " . ($msg['media'] ?? 'NULL') . "\n";
            echo "Mimetype: " . ($msg['mimetype'] ?? 'NULL') . "\n";
            echo str_repeat('-', 80) . "\n";
        }
    } else {
        echo "Error or unexpected response format\n";
        print_r($data);
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
