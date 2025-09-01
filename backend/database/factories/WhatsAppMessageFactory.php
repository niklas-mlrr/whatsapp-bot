<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\User;
use App\Models\Chat;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WhatsAppMessage>
 */
class WhatsAppMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sender_id' => User::factory(),
            'chat_id' => Chat::factory(),
            'type' => $this->faker->randomElement(['text', 'image', 'video', 'audio', 'document']),
            'status' => $this->faker->randomElement(['sent', 'delivered', 'read']),
            'content' => $this->faker->realText(60),
            'media_url' => null,
            'media_type' => null,
            'media_size' => null,
            'read_at' => null,
            'metadata' => [],
        ];
    }
}
