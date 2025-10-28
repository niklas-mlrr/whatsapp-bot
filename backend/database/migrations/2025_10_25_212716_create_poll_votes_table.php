<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('poll_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('whatsapp_messages')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->unsignedInteger('option_index'); // 0-based index of the selected option
            $table->timestamp('voted_at')->useCurrent();
            $table->timestamps();
            
            // Ensure a user can only vote once per poll (they can change their vote)
            $table->unique(['message_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('poll_votes');
    }
};
