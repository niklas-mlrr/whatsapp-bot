<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixPollMetadata extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'polls:fix-metadata';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Add poll_vote_counts to polls that are missing it';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $polls = DB::table('whatsapp_messages')
            ->where('type', 'poll')
            ->get();

        $updated = 0;

        foreach ($polls as $poll) {
            $metadata = json_decode($poll->metadata, true);
            
            // Skip if poll_data doesn't exist
            if (!isset($metadata['poll_data']) || !isset($metadata['poll_data']['options'])) {
                continue;
            }
            
            // Initialize vote counts for each option if not present
            if (!isset($metadata['poll_vote_counts']) || empty($metadata['poll_vote_counts'])) {
                $voteCounts = [];
                foreach ($metadata['poll_data']['options'] as $index => $option) {
                    $voteCounts[(string)$index] = 0;
                }
                
                $metadata['poll_vote_counts'] = $voteCounts;
                
                DB::table('whatsapp_messages')
                    ->where('id', $poll->id)
                    ->update(['metadata' => json_encode($metadata)]);
                
                $this->info("Updated poll ID {$poll->id}");
                $updated++;
            }
        }

        $this->info("Done! Updated {$updated} polls.");
        
        return 0;
    }
}
