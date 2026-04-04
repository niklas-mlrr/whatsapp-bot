<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Drop the legacy 'messages' table which has been superseded by 'whatsapp_messages'.
     * The messages table was created in the original unified schema but was never used
     * in production - all message operations use the WhatsAppMessage model instead.
     */
    public function up(): void
    {
        Schema::dropIfExists('messages');
    }

    /**
     * Reverse the migrations.
     *
     * Recreate the legacy messages table if needed (unlikely).
     */
    public function down(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->string('sender');
            $table->string('chat');
            $table->string('type');
            $table->text('content')->nullable();
            $table->string('media')->nullable();
            $table->string('mimetype')->nullable();
            $table->dateTime('sending_time')->nullable();
            $table->timestamps();
        });
    }
};