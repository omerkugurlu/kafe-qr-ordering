<?php

namespace Database\Factories;

use App\Models\Announcement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => Announcement::TYPE_ANNOUNCEMENT,
            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'is_active' => true,
        ];
    }
}
