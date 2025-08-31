<?php

/**
 * Old Migration Cleanup Script
 * 
 * This script safely deletes old migration files after the unification process.
 * It only deletes files that are no longer needed after the unified migration.
 * 
 * WARNING: Only run this script AFTER successfully running the unification script!
 */

$migrationsDir = __DIR__ . '/database/migrations/';
$unifiedMigration = '2025_01_01_000000_unified_database_schema.php';

echo "=== Old Migration Cleanup Script ===\n";
echo "This script will delete old migration files after unification.\n\n";

// Check if unified migration exists
if (!file_exists($migrationsDir . $unifiedMigration)) {
    echo "ERROR: Unified migration file not found!\n";
    echo "Please run the unification script first.\n";
    exit(1);
}

// Get all migration files
$migrationFiles = glob($migrationsDir . '*.php');
$migrationFiles = array_filter($migrationFiles, function($file) {
    return basename($file) !== $unifiedMigration;
});

if (empty($migrationFiles)) {
    echo "No old migration files found to delete.\n";
    exit(0);
}

echo "Found " . count($migrationFiles) . " old migration files:\n";
foreach ($migrationFiles as $file) {
    echo "  - " . basename($file) . "\n";
}

echo "\nAre you sure you want to delete these files? (yes/no): ";
$handle = fopen("php://stdin", "r");
$line = fgets($handle);
fclose($handle);

if (trim($line) !== 'yes') {
    echo "Operation cancelled.\n";
    exit(0);
}

echo "\nDeleting old migration files...\n";
$deletedCount = 0;
$errors = [];

foreach ($migrationFiles as $file) {
    $filename = basename($file);
    try {
        if (unlink($file)) {
            echo "  ✓ Deleted: $filename\n";
            $deletedCount++;
        } else {
            echo "  ✗ Failed to delete: $filename\n";
            $errors[] = $filename;
        }
    } catch (Exception $e) {
        echo "  ✗ Error deleting $filename: " . $e->getMessage() . "\n";
        $errors[] = $filename;
    }
}

echo "\n=== Cleanup Complete ===\n";
echo "Successfully deleted: $deletedCount files\n";

if (!empty($errors)) {
    echo "Failed to delete: " . count($errors) . " files\n";
    echo "You may need to delete these manually:\n";
    foreach ($errors as $error) {
        echo "  - $error\n";
    }
}

echo "\nYour migration history is now clean and unified!\n";
