<?php

namespace Database\Factories;

use App\Models\ClinicSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicSchedule>
 */
class ClinicScheduleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'day_of_week' => fake()->unique()->numberBetween(0, 6),
            'start_time' => '08:00',
            'end_time' => '17:00',
            'slot_minutes' => 30,
            'capacity' => 2,
            'is_active' => true,
        ];
    }
}
