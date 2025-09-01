<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Chat;
use App\Models\User;
use App\Models\WhatsAppMessage;

class DummyMessageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $chats = Chat::all();

        foreach ($chats as $chat) {
            $participants = $chat->users()->get();

            if ($participants->count() === 0) {
                // Ensure at least the creator is a participant if no users attached
                if ($chat->created_by && ($creator = User::find($chat->created_by))) {
                    $chat->users()->syncWithoutDetaching([$creator->id => ['role' => 'admin']]);
                    $participants = collect([$creator]);
                } else {
                    // Skip if no participants can be determined
                    continue;
                }
            }

            $senderIds = $participants->pluck('id')->values()->all();
            $messagesCreated = [];

            // Create 8 sample messages per chat
            for ($i = 0; $i < 8; $i++) {
                $senderId = $senderIds[$i % count($senderIds)];
                $status = $i < 6 ? 'sent' : ($i === 6 ? 'delivered' : 'read');

                $message = WhatsAppMessage::create([
                    'chat_id' => $chat->id,
                    'sender_id' => $senderId,
                    'type' => 'text',
                    'status' => $status,
                    'content' => $this->sampleContent($chat, $i),
                    'media_url' => null,
                    'media_type' => null,
                    'media_size' => null,
                    'read_at' => $status === 'read' ? now() : null,
                    'metadata' => [
                        'seeded' => true,
                        'index' => $i,
                    ],
                ]);

                $messagesCreated[] = $message;
            }

            // Update chat last message references
            $lastMessage = collect($messagesCreated)->last();
            if ($lastMessage) {
                $chat->last_message_id = $lastMessage->id;
                $chat->last_message_at = $lastMessage->created_at;
                $chat->unread_count = 0;
                $chat->save();
            }
        }
    }

    protected function sampleContent(Chat $chat, int $i): string
    {
        $base = $chat->is_group ? "Group '{$chat->name}'" : "Direct chat '{$chat->name}'";
        $samples = [
            "Hello there! This is sample message #$i in $base.",
            "Just checking in on $base.",
            "FYI: seed message #$i for $base.",
            "Let's keep the conversation going in $base.",
            "Sample message #$i with no media.",
            "Automated seed content for $base.",
            "Almost done seeding $base.",
            "Last seed message for $base.",
        ];
        return $samples[$i % count($samples)];
    }
}
