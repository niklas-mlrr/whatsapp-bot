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
        Schema::table('chats', function (Blueprint $table) {
            // Remove profile fields from chats table - now handled by contacts table
            if (Schema::hasColumn('chats', 'contact_profile_picture_url')) {
                $table->dropColumn('contact_profile_picture_url');
            }
            if (Schema::hasColumn('chats', 'contact_description')) {
                $table->dropColumn('contact_description');
            }
            if (Schema::hasColumn('chats', 'contact_info_updated_at')) {
                $table->dropColumn('contact_info_updated_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            // Restore profile fields to chats table
            $table->text('contact_profile_picture_url')->nullable();
            $table->text('contact_description')->nullable();
            $table->timestamp('contact_info_updated_at')->nullable();
        });
    }
};
