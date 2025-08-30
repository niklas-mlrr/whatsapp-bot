<?php

use Illuminate\Support\Facades\Route;

Route::get('/test-memory', function () {
    $memoryInfo = [
        'memory_limit' => ini_get('memory_limit'),
        'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
        'peak_memory' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB',
        'max_execution_time' => ini_get('max_execution_time'),
        'php_version' => PHP_VERSION,
    ];
    
    return response()->json([
        'status' => 'success',
        'message' => 'Memory configuration is working properly',
        'memory_info' => $memoryInfo,
        'timestamp' => now()->toISOString()
    ]);
});

Route::get('/test-memory-alloc', function () {
    $startMemory = memory_get_usage(true);
    
    // Test memory allocation
    $testArray = [];
    for ($i = 0; $i < 500000; $i++) {
        $testArray[] = "test_string_" . $i;
    }
    
    $endMemory = memory_get_usage(true);
    $allocatedMemory = round(($endMemory - $startMemory) / 1024 / 1024, 2);
    
    return response()->json([
        'status' => 'success',
        'message' => 'Memory allocation test completed',
        'memory_allocated' => $allocatedMemory . ' MB',
        'final_memory_usage' => round($endMemory / 1024 / 1024, 2) . ' MB',
        'memory_limit' => ini_get('memory_limit')
    ]);
});
