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
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('name');
            $table->string('phone', 100); // WhatsApp JID format (e.g., 491234567890@s.whatsapp.net)
            $table->text('profile_picture_url')->nullable();
            $table->text('bio')->nullable();
            $table->json('metadata')->nullable(); // For additional data
            $table->timestamps();
            
            // Ensure one contact per phone number per user
            $table->unique(['user_id', 'phone']);
            
            // Index for faster lookups
            $table->index('phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
