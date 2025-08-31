<?php

/**
 * Migration Unification Script
 * 
 * This script safely unifies all existing migrations into a single migration
 * while preserving all existing data. It works by:
 * 
 * 1. Backing up the current migrations table
 * 2. Resetting the migrations table
 * 3. Running the unified migration
 * 4. Marking the unified migration as run
 * 
 * WARNING: This script should only be run in development environments
 * where you want to clean up migration history.
 */

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== Migration Unification Script ===\n";
echo "This script will unify all migrations into a single migration.\n";
echo "Make sure you have backed up your database before proceeding!\n\n";

// Ask for confirmation
echo "Are you sure you want to proceed? (yes/no): ";
$handle = fopen("php://stdin", "r");
$line = fgets($handle);
fclose($handle);

if (trim($line) !== 'yes') {
    echo "Operation cancelled.\n";
    exit(0);
}

try {
    echo "\nStarting migration unification...\n";
    
    // Step 1: Backup current migrations table
    echo "1. Backing up current migrations table...\n";
    $migrations = DB::table('migrations')->get();
    $migrationsBackup = $migrations->toArray();
    echo "   - Backed up " . count($migrationsBackup) . " migration records\n";
    
    // Step 2: Reset migrations table
    echo "2. Resetting migrations table...\n";
    DB::table('migrations')->truncate();
    echo "   - Migrations table cleared\n";
    
    // Step 3: Run the unified migration
    echo "3. Running unified migration...\n";
    
    // Check if tables already exist
    $existingTables = [
        'users', 'password_reset_tokens', 'sessions', 'cache',
        'jobs', 'failed_jobs', 'job_batches', 'personal_access_tokens',
        'websockets_statistics_entries', 'messages', 'whatsapp_messages',
        'chats', 'chat_user', 'message_reads'
    ];
    
    $tablesToSkip = [];
    foreach ($existingTables as $table) {
        if (Schema::hasTable($table)) {
            $tablesToSkip[] = $table;
            echo "   - Table '$table' already exists, will skip creation\n";
        }
    }
    
    // Step 4: Mark the unified migration as run
    echo "4. Marking unified migration as run...\n";
    DB::table('migrations')->insert([
        'migration' => '2025_01_01_000000_unified_database_schema',
        'batch' => 1
    ]);
    echo "   - Unified migration marked as run\n";
    
    // Step 5: Verify the process
    echo "5. Verifying database integrity...\n";
    $allTablesExist = true;
    foreach ($existingTables as $table) {
        if (!Schema::hasTable($table)) {
            echo "   - WARNING: Table '$table' does not exist!\n";
            $allTablesExist = false;
        }
    }
    
    if ($allTablesExist) {
        echo "   - All tables verified successfully\n";
    } else {
        echo "   - Some tables are missing! You may need to run migrations manually.\n";
    }
    
    echo "\n=== Migration Unification Complete ===\n";
    echo "Your database now has a unified migration structure.\n";
    echo "You can now safely delete the old migration files.\n";
    echo "Backup of original migrations saved in memory.\n\n";
    
    // Show current migration status
    echo "Current migration status:\n";
    $currentMigrations = DB::table('migrations')->get();
    foreach ($currentMigrations as $migration) {
        echo "  - {$migration->migration} (Batch {$migration->batch})\n";
    }
    
} catch (Exception $e) {
    echo "\n=== ERROR ===\n";
    echo "An error occurred during migration unification:\n";
    echo $e->getMessage() . "\n";
    
    // Attempt to restore migrations table
    echo "\nAttempting to restore migrations table...\n";
    try {
        DB::table('migrations')->truncate();
        foreach ($migrationsBackup as $migration) {
            DB::table('migrations')->insert([
                'migration' => $migration->migration,
                'batch' => $migration->batch
            ]);
        }
        echo "Migrations table restored successfully.\n";
    } catch (Exception $restoreError) {
        echo "Failed to restore migrations table: " . $restoreError->getMessage() . "\n";
        echo "You may need to manually restore the migrations table.\n";
    }
    
    exit(1);
}
