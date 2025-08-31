<?php
require_once 'vendor/autoload.php';

// Bootstrap Laravel
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

try {
    // Get the authenticated user (John Doe)
    $user = \App\Models\User::first();
    
    if (!$user) {
        echo "❌ User not found\n";
        exit(1);
    }
    
    echo "✅ User found: " . $user->name . " (ID: " . $user->id . ")\n";
    
    // Get the user's token
    $token = $user->tokens()->first();
    if (!$token) {
        echo "❌ No token found for user\n";
        exit(1);
    }
    
    echo "✅ Token found: " . $token->name . "\n";
    $plainToken = $token->token;
    echo "Token value: " . substr($plainToken, 0, 20) . "...\n";
    
    // Test the custom broadcasting auth endpoint
    echo "\nTesting custom broadcasting auth endpoint...\n";
    
    $request = \Illuminate\Http\Request::create('/broadcasting/auth', 'POST', [
        'socket_id' => 'test-socket-123',
        'channel_name' => 'private-chat.1'
    ]);
    
    // Set the Authorization header
    $request->headers->set('Authorization', 'Bearer ' . $plainToken);
    $request->headers->set('Accept', 'application/json');
    
    echo "Request headers:\n";
    echo "- Authorization: Bearer " . substr($plainToken, 0, 20) . "...\n";
    echo "- Accept: " . $request->header('Accept') . "\n";
    
    // Get the response
    $response = app()->handle($request);
    
    echo "\nResponse status: " . $response->getStatusCode() . "\n";
    echo "Response content: " . $response->getContent() . "\n";
    
    if ($response->getStatusCode() === 200) {
        echo "✅ Custom broadcasting auth successful!\n";
        echo "🎉 The WebSocket test should now work!\n";
    } else {
        echo "❌ Custom broadcasting auth failed!\n";
        
        if ($response->getStatusCode() === 401) {
            echo "🔍 Authentication issue - check middleware configuration\n";
        } elseif ($response->getStatusCode() === 404) {
            echo "🔍 Route not found - check route registration\n";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>


