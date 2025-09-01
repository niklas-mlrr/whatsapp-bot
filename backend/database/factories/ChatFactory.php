<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\User;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Chat>
 */
class ChatFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $isGroup = $this->faker->boolean(40);
        return [
            'name' => $isGroup ? $this->faker->words(2, true) : null,
            'type' => $isGroup ? 'group' : 'private',
            'is_group' => $isGroup,
            'created_by' => User::factory(),
            'metadata' => [],
            'participants' => [],
            'is_archived' => false,
            'is_muted' => false,
            'unread_count' => 0,
        ];
    }
}
