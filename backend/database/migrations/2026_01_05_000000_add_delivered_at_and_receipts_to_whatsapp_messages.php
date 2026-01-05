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
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            if (!Schema::hasColumn('whatsapp_messages', 'delivered_at')) {
                $table->timestamp('delivered_at')->nullable()->after('status');
            }

            if (!Schema::hasColumn('whatsapp_messages', 'read_by')) {
                $table->json('read_by')->nullable()->after('read_at');
            }
        });

        if (!Schema::hasTable('message_receipts')) {
            Schema::create('message_receipts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('message_id')->constrained('whatsapp_messages')->cascadeOnDelete();
                $table->string('participant_id', 32);
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamps();

                $table->unique(['message_id', 'participant_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('message_receipts')) {
            Schema::drop('message_receipts');
        }

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            if (Schema::hasColumn('whatsapp_messages', 'delivered_at')) {
                $table->dropColumn('delivered_at');
            }
            if (Schema::hasColumn('whatsapp_messages', 'read_by')) {
                $table->dropColumn('read_by');
            }
        });
    }
};
