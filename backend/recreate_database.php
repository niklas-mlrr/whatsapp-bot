<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

echo "Starting database recreation...\n";

try {
    // Drop all existing tables if they exist
    echo "Dropping existing tables...\n";
    Schema::dropIfExists('websockets_statistics_entries');
    Schema::dropIfExists('personal_access_tokens');
    Schema::dropIfExists('whatsapp_messages');
    Schema::dropIfExists('chat_user');
    Schema::dropIfExists('chats');
    Schema::dropIfExists('users');
    Schema::dropIfExists('cache');
    Schema::dropIfExists('jobs');
    Schema::dropIfExists('migrations');
    
    echo "Creating users table...\n";
    Schema::create('users', function($table) {
        $table->bigIncrements('id');
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        $table->string('phone', 20)->nullable();
        $table->enum('status', ['online', 'offline', 'away'])->default('offline');
        $table->timestamp('last_seen_at')->nullable();
        $table->rememberToken();
        $table->timestamps();
    });

    echo "Creating cache table...\n";
    Schema::create('cache', function($table) {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration');
    });

    echo "Creating jobs table...\n";
    Schema::create('jobs', function($table) {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    echo "Creating personal_access_tokens table...\n";
    Schema::create('personal_access_tokens', function($table) {
        $table->id();
        $table->morphs('tokenable');
        $table->string('name');
        $table->string('token', 64)->unique();
        $table->text('abilities')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });

    echo "Creating chats table...\n";
    Schema::create('chats', function($table) {
        $table->id();
        $table->string('name')->nullable();
        $table->enum('type', ['private', 'group'])->default('private');
        $table->unsignedBigInteger('last_message_id')->nullable();
        $table->integer('unread_count')->default(0);
        $table->json('participants')->nullable();
        $table->timestamps();
        
        $table->index(['type', 'last_message_id']);
    });

    echo "Creating whatsapp_messages table...\n";
    Schema::create('whatsapp_messages', function($table) {
        $table->id();
        $table->unsignedBigInteger('chat_id');
        $table->unsignedBigInteger('sender_id');
        $table->text('content');
        $table->enum('type', ['text', 'image', 'video', 'audio', 'document', 'location'])->default('text');
        $table->string('media_url')->nullable();
        $table->string('media_type')->nullable();
        $table->integer('media_size')->nullable();
        $table->enum('status', ['sent', 'delivered', 'read'])->default('sent');
        $table->timestamp('read_at')->nullable();
        $table->json('metadata')->nullable();
        $table->timestamps();
        
        $table->foreign('chat_id')->references('id')->on('chats')->onDelete('cascade');
        $table->foreign('sender_id')->references('id')->on('users')->onDelete('cascade');
        $table->index(['chat_id', 'created_at']);
        $table->index(['sender_id', 'created_at']);
    });

    echo "Creating chat_user pivot table...\n";
    Schema::create('chat_user', function($table) {
        $table->id();
        $table->unsignedBigInteger('chat_id');
        $table->unsignedBigInteger('user_id');
        $table->enum('role', ['admin', 'member'])->default('member');
        $table->timestamp('joined_at')->default(DB::raw('CURRENT_TIMESTAMP'));
        $table->timestamp('left_at')->nullable();
        
        $table->foreign('chat_id')->references('id')->on('chats')->onDelete('cascade');
        $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        $table->unique(['chat_id', 'user_id']);
    });

    echo "Creating websockets_statistics_entries table...\n";
    Schema::create('websockets_statistics_entries', function($table) {
        $table->increments('id');
        $table->string('app_id');
        $table->integer('peak_connection_count');
        $table->integer('websocket_message_count');
        $table->integer('api_message_count');
        $table->timestamps();
    });

    echo "Creating migrations table...\n";
    Schema::create('migrations', function($table) {
        $table->increments('id');
        $table->string('migration');
        $table->integer('batch');
    });

    // Insert migration records
    echo "Recording migrations...\n";
    $migrations = [
        ['migration' => '0001_01_01_000000_create_users_table', 'batch' => 1],
        ['migration' => '0001_01_01_000001_create_cache_table', 'batch' => 1],
        ['migration' => '0001_01_01_000002_create_jobs_table', 'batch' => 1],
        ['migration' => '2024_01_01_000003_create_whatsapp_messages_table', 'batch' => 1],
        ['migration' => '2025_07_26_141600_add_media_to_whatsapp_messages_table', 'batch' => 1],
        ['migration' => '2025_07_27_184600_simplify_users_table', 'batch' => 1],
        ['migration' => '2025_07_27_191304_create_personal_access_tokens_table', 'batch' => 1],
        ['migration' => '2025_07_27_211927_create_chat_tables', 'batch' => 1],
        ['migration' => '2025_07_27_212516_update_messages_table_for_chat_relationships', 'batch' => 1],
        ['migration' => '2025_07_27_213336_fix_chat_tables_foreign_keys', 'batch' => 1],
        ['migration' => '2025_07_28_000001_add_fields_to_users_table', 'batch' => 1],
        ['migration' => '2025_07_28_000002_create_websockets_statistics_entries_table', 'batch' => 1],
        ['migration' => '2025_07_29_140900_fix_duplicate_last_message_id_in_chats_table', 'batch' => 1],
        ['migration' => '2025_07_29_141000_fix_duplicate_foreign_keys', 'batch' => 1],
        ['migration' => '2025_07_29_141600_reset_migrations', 'batch' => 1],
        ['migration' => '2025_07_29_142000_fix_last_message_id_column', 'batch' => 1],
        ['migration' => '2025_07_29_142100_fix_last_message_id_simple', 'batch' => 1],
        ['migration' => '2025_07_29_142500_fix_migration_order', 'batch' => 1],
        ['migration' => '2025_07_29_154948_fix_users_table_structure', 'batch' => 1],
        ['migration' => '2025_07_29_211154_add_participants_to_chats_table', 'batch' => 1],
        ['migration' => '2025_07_29_212155_add_phone_column_to_users_table', 'batch' => 1],
        ['migration' => '2025_07_29_212715_add_status_to_users_table', 'batch' => 1],
        ['migration' => '2025_07_29_213844_2025_07_29_235900_cleanup_redundant_migrations', 'batch' => 1],
        ['migration' => '2025_07_30_000000_modify_phone_column_length', 'batch' => 1],
        ['migration' => '2025_07_30_000001_add_unread_count_to_chats', 'batch' => 1],
        ['migration' => '2025_07_30_212100_fix_chat_user_relationships', 'batch' => 1],
        ['migration' => '2025_07_30_215310_add_columns_to_messages_table', 'batch' => 1],
        ['migration' => '2025_07_30_220000_finalize_whatsapp_schema', 'batch' => 1],
        ['migration' => '2025_07_30_230000_complete_whatsapp_schema', 'batch' => 1]
    ];

    foreach ($migrations as $migration) {
        DB::table('migrations')->insert($migration);
    }

    // Create some sample data
    echo "Creating sample data...\n";
    
    // Create sample users
    $users = [
        [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => bcrypt('password'),
            'phone' => '+1234567890',
            'status' => 'online'
        ],
        [
            'name' => 'Jane Smith',
            'email' => 'jane@example.com',
            'password' => bcrypt('password'),
            'phone' => '+0987654321',
            'status' => 'offline'
        ]
    ];

    foreach ($users as $userData) {
        DB::table('users')->insert($userData);
    }

    // Create sample chats
    $chats = [
        [
            'name' => 'General Chat',
            'type' => 'group',
            'participants' => json_encode([1, 2])
        ]
    ];

    foreach ($chats as $chatData) {
        DB::table('chats')->insert($chatData);
    }

    // Create chat-user relationships
    $chatUsers = [
        ['chat_id' => 1, 'user_id' => 1, 'role' => 'admin'],
        ['chat_id' => 1, 'user_id' => 2, 'role' => 'member']
    ];

    foreach ($chatUsers as $chatUser) {
        DB::table('chat_user')->insert($chatUser);
    }

    // Create sample messages
    $messages = [
        [
            'chat_id' => 1,
            'sender_id' => 1,
            'content' => 'Hello everyone!',
            'type' => 'text',
            'status' => 'read'
        ],
        [
            'chat_id' => 1,
            'sender_id' => 2,
            'content' => 'Hi John!',
            'type' => 'text',
            'status' => 'delivered'
        ]
    ];

    foreach ($messages as $messageData) {
        DB::table('whatsapp_messages')->insert($messageData);
    }

    // Update chat with last message
    DB::table('chats')->where('id', 1)->update(['last_message_id' => 2]);

    echo "Database recreation completed successfully!\n";
    echo "Created tables: users, cache, jobs, personal_access_tokens, chats, whatsapp_messages, chat_user, websockets_statistics_entries, migrations\n";
    echo "Added sample data: 2 users, 1 chat, 2 messages\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
} 