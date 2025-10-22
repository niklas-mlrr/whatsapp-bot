<?php

/**
 * This script moves old log files from the root logs directory
 * into the new year/month folder structure.
 */

$logsPath = __DIR__ . '/storage/logs';

// Get all log files in the root logs directory
$files = glob($logsPath . '/*.log');

foreach ($files as $file) {
    $filename = basename($file);
    
    // Match pattern: prefix-YYYY-MM-DD.log or prefix.log
    if (preg_match('/^(laravel|whatsapp)-(\d{4})-(\d{2})-(\d{2})\.log$/', $filename, $matches)) {
        $prefix = $matches[1];
        $year = $matches[2];
        $month = $matches[3];
        $day = $matches[4];
        
        // Create target directory
        $targetDir = $logsPath . '/' . $year . '/' . $month;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        
        // New filename format: prefix-DD.log
        $newFilename = $prefix . '-' . $day . '.log';
        $targetPath = $targetDir . '/' . $newFilename;
        
        // Move the file
        if (rename($file, $targetPath)) {
            echo "Moved: {$filename} -> {$year}/{$month}/{$newFilename}\n";
        } else {
            echo "Failed to move: {$filename}\n";
        }
    } elseif ($filename === 'whatsapp.log') {
        // This is the old single WhatsApp log file
        echo "Found old whatsapp.log - you may want to manually review and delete it\n";
        echo "Location: {$file}\n";
    }
}

echo "\nCleanup completed!\n";
echo "Old log files have been organized into year/month folders.\n";
