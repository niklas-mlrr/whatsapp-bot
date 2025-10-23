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
        // Note: changing an existing column type requires doctrine/dbal
        Schema::table('users', function (Blueprint $table) {
            // Make profile_picture_url TEXT to support long CDN URLs
            $table->text('profile_picture_url')->nullable()->change();
        });

        Schema::table('chats', function (Blueprint $table) {
            // Make contact_profile_picture_url TEXT to support long CDN URLs
            $table->text('contact_profile_picture_url')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Revert back to VARCHAR(255)
            $table->string('profile_picture_url', 255)->nullable()->change();
        });

        Schema::table('chats', function (Blueprint $table) {
            // Revert back to VARCHAR(255)
            $table->string('contact_profile_picture_url', 255)->nullable()->change();
        });
    }
};
