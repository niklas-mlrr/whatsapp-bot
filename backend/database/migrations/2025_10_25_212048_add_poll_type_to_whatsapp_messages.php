<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Modify the enum type to include 'poll'
        DB::statement("ALTER TABLE whatsapp_messages MODIFY COLUMN type ENUM('text', 'image', 'video', 'audio', 'document', 'location', 'reaction', 'poll') NOT NULL DEFAULT 'text'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove 'poll' from the enum
        DB::statement("ALTER TABLE whatsapp_messages MODIFY COLUMN type ENUM('text', 'image', 'video', 'audio', 'document', 'location', 'reaction') NOT NULL DEFAULT 'text'");
    }
};
