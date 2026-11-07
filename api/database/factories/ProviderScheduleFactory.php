<?php

namespace Database\Factories;

use App\Models\ProviderSchedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderSchedule>
 */
class ProviderScheduleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->dentist(),
            'day_of_week' => 2,
            'start_time' => '08:00',
            'end_time' => '17:00',
            'slot_minutes' => 30,
            'capacity' => 1,
            'is_active' => true,
        ];
    }
}
