<?php

// Test script to verify memory limit configuration
echo "=== Memory Configuration Test ===\n";

// Check current memory limit
echo "Current memory limit: " . ini_get('memory_limit') . "\n";

// Check if custom config file exists
$customPhpIni = __DIR__ . '/config/custom-php.ini';
echo "Custom PHP config exists: " . (file_exists($customPhpIni) ? 'Yes' : 'No') . "\n";

// Try to load custom config
if (file_exists($customPhpIni)) {
    $config = parse_ini_file($customPhpIni);
    echo "Custom config loaded: " . (is_array($config) ? 'Yes' : 'No') . "\n";
    
    if (isset($config['memory_limit'])) {
        echo "Custom memory limit: " . $config['memory_limit'] . "\n";
        ini_set('memory_limit', $config['memory_limit']);
        echo "Memory limit after custom config: " . ini_get('memory_limit') . "\n";
    }
}

// Test memory allocation
echo "\n=== Memory Allocation Test ===\n";
$startMemory = memory_get_usage(true);
echo "Starting memory usage: " . round($startMemory / 1024 / 1024, 2) . " MB\n";

// Try to allocate a reasonable amount of memory
$testArray = [];
for ($i = 0; $i < 1000000; $i++) {
    $testArray[] = "test_string_" . $i;
    if ($i % 100000 == 0) {
        $currentMemory = memory_get_usage(true);
        echo "Memory at iteration $i: " . round($currentMemory / 1024 / 1024, 2) . " MB\n";
    }
}

$endMemory = memory_get_usage(true);
echo "Final memory usage: " . round($endMemory / 1024 / 1024, 2) . " MB\n";
echo "Peak memory usage: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";

echo "\n=== Test Completed Successfully ===\n";
