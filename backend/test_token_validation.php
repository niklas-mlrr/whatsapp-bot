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
    echo "Token ID: " . $token->id . "\n";
    echo "Token Hash: " . substr($token->token, 0, 20) . "...\n";
    
    // Test different token formats
    echo "\n🔍 Testing different token formats...\n";
    
    // Format 1: Just the hash
    $tokenHash = $token->token;
    echo "\n1. Testing with hash only: " . substr($tokenHash, 0, 20) . "...\n";
    
    $request1 = \Illuminate\Http\Request::create('/broadcasting/auth', 'POST', [
        'socket_id' => 'test-socket-123',
        'channel_name' => 'private-chat.1'
    ]);
    $request1->headers->set('Authorization', 'Bearer ' . $tokenHash);
    
    $response1 = app()->handle($request1);
    echo "   Status: " . $response1->getStatusCode() . " - " . $response1->getContent() . "\n";
    
    // Format 2: ID|hash format
    $tokenFull = $token->id . '|' . $token->token;
    echo "\n2. Testing with ID|hash format: " . substr($tokenFull, 0, 20) . "...\n";
    
    $request2 = \Illuminate\Http\Request::create('/broadcasting/auth', 'POST', [
        'socket_id' => 'test-socket-123',
        'channel_name' => 'private-chat.1'
    ]);
    $request2->headers->set('Authorization', 'Bearer ' . $tokenFull);
    
    $response2 = app()->handle($request2);
    echo "   Status: " . $response2->getStatusCode() . " - " . $response2->getContent() . "\n";
    
    // Format 3: Test with plainTextToken if available
    if (method_exists($token, 'getPlainTextToken')) {
        $plainToken = $token->getPlainTextToken();
        echo "\n3. Testing with plainTextToken: " . substr($plainToken, 0, 20) . "...\n";
        
        $request3 = \Illuminate\Http\Request::create('/broadcasting/auth', 'POST', [
            'socket_id' => 'test-socket-123',
            'channel_name' => 'private-chat.1'
        ]);
        $request3->headers->set('Authorization', 'Bearer ' . $plainToken);
        
        $response3 = app()->handle($request3);
        echo "   Status: " . $response3->getStatusCode() . " - " . $response3->getContent() . "\n";
    }
    
    // Check which format worked
    echo "\n📋 SUMMARY:\n";
    if ($response1->getStatusCode() === 200) {
        echo "✅ Hash-only format works!\n";
        echo "🔑 Use this token: " . $tokenHash . "\n";
    } elseif ($response2->getStatusCode() === 200) {
        echo "✅ ID|hash format works!\n";
        echo "🔑 Use this token: " . $tokenFull . "\n";
    } elseif (isset($response3) && $response3->getStatusCode() === 200) {
        echo "✅ PlainTextToken format works!\n";
        echo "🔑 Use this token: " . $plainToken . "\n";
    } else {
        echo "❌ None of the token formats worked\n";
        echo "🔍 Check middleware and authentication configuration\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>


