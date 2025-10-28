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
        // Update existing poll messages to add poll_vote_counts
        $polls = \DB::table('whatsapp_messages')
            ->where('type', 'poll')
            ->get();
        
        foreach ($polls as $poll) {
            $metadata = json_decode($poll->metadata, true);
            
            // Skip if poll_data doesn't exist
            if (!isset($metadata['poll_data']) || !isset($metadata['poll_data']['options'])) {
                continue;
            }
            
            // Initialize vote counts for each option as an object
            $voteCounts = new \stdClass();
            foreach ($metadata['poll_data']['options'] as $index => $option) {
                $voteCounts->{$index} = 0;
            }
            
            // Add poll_vote_counts to metadata if it doesn't exist
            if (!isset($metadata['poll_vote_counts']) || empty($metadata['poll_vote_counts'])) {
                $metadata['poll_vote_counts'] = $voteCounts;
                
                \DB::table('whatsapp_messages')
                    ->where('id', $poll->id)
                    ->update(['metadata' => json_encode($metadata)]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove poll_vote_counts from poll messages
        \DB::statement("
            UPDATE whatsapp_messages
            SET metadata = JSON_REMOVE(metadata, '$.poll_vote_counts')
            WHERE type = 'poll'
        ");
    }
};
