<?php

namespace Database\Factories;

use App\Models\AnalyticsSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyticsSnapshot>
 */
class AnalyticsSnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date' => today()->toDateString(),
            'hour' => fake()->numberBetween(8, 16),
            'arrival_count' => fake()->numberBetween(1, 10),
            'avg_wait_time' => fake()->numberBetween(2, 20),
            'avg_consult_time' => fake()->numberBetween(3, 15),
            'peak_hour' => false,
            'event_day' => false,
        ];
    }
}
