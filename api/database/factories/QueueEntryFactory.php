<?php

namespace Database\Factories;

use App\Models\QueueEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QueueEntry>
 */
class QueueEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'queue_number' => fake()->unique()->numberBetween(1, 500),
            'queue_date' => today(),
            'patient_id' => User::factory()->patient(),
            'source' => 'walk_in',
            'status' => 'waiting',
            'joined_at' => now(),
        ];
    }
}
