<?php

use App\Events\MessageSent;
use App\Models\User;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

Route::get('/test-broadcast', function (Request $request) {
    try {
        // Create a test message
        $user = User::first();
        
        if (!$user) {
            // Create a test user if none exists
            $user = new User();
            $user->name = 'Test User';
            $user->email = 'test@example.com';
            $user->password = bcrypt(env('TEST_USER_PASSWORD', \Illuminate\Support\Str::random(32)));
            $user->phone = '1234567890';
            $user->save();
        }
        
        $message = WhatsAppMessage::create([
            'sender' => $user->phone ?? '1234567890',
            'receiver' => '1234567890',
            'message' => 'Test message ' . now()->format('H:i:s'),
            'status' => 'sent',
            'direction' => 'outbound',
            'chat' => 'test-chat',
            'timestamp' => now(),
        ]);
        
        // Broadcast the message
        broadcast(new MessageSent($message, 'test'))->toOthers();
        
        return response()->json([
            'status' => 'success',
            'message' => 'Test message broadcasted',
            'data' => $message
        ]);
    } catch (\Exception $e) {
        \Log::error('Broadcast error: ' . $e->getMessage());
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage()
        ], 500);
    }
})->middleware('web');

// Add CORS headers for API routes
Route::options('/{any}', function () {
    return response('', 200)
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, X-CSRF-TOKEN, Authorization')
        ->header('Access-Control-Allow-Credentials', 'true');
})->where('any', '.*');
