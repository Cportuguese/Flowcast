<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'patient_id' => User::factory()->patient(),
            'scheduled_at' => now()->addDay()->setTime(9, 0),
            'reason' => fake()->sentence(),
            'status' => 'booked',
        ];
    }
}
