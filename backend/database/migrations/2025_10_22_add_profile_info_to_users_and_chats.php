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
        // Add profile picture and bio to users table
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'profile_picture_url')) {
                $table->string('profile_picture_url')->nullable();
            }
            if (!Schema::hasColumn('users', 'bio')) {
                $table->text('bio')->nullable();
            }
        });

        // Add profile picture and description to chats table
        Schema::table('chats', function (Blueprint $table) {
            if (!Schema::hasColumn('chats', 'contact_profile_picture_url')) {
                $table->string('contact_profile_picture_url')->nullable();
            }
            if (!Schema::hasColumn('chats', 'contact_description')) {
                $table->text('contact_description')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'profile_picture_url')) {
                $table->dropColumn('profile_picture_url');
            }
            if (Schema::hasColumn('users', 'bio')) {
                $table->dropColumn('bio');
            }
        });

        Schema::table('chats', function (Blueprint $table) {
            if (Schema::hasColumn('chats', 'contact_profile_picture_url')) {
                $table->dropColumn('contact_profile_picture_url');
            }
            if (Schema::hasColumn('chats', 'contact_description')) {
                $table->dropColumn('contact_description');
            }
        });
    }
};
