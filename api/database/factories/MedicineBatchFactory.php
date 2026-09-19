<?php

namespace Database\Factories;

use App\Models\Medicine;
use App\Models\MedicineBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MedicineBatch>
 */
class MedicineBatchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'medicine_id' => Medicine::factory(),
            'batch_number' => strtoupper(fake()->bothify('B-####??')),
            'expiry_date' => today()->addYear(),
            'received_date' => today(),
            'quantity_received' => 100,
            // Mirrors stock-in: a fresh batch has everything left.
            'quantity_remaining' => fn (array $attributes) => $attributes['quantity_received'],
        ];
    }

    public function expiresIn(int $days): static
    {
        return $this->state(['expiry_date' => today()->addDays($days)]);
    }

    public function expired(): static
    {
        return $this->state(['expiry_date' => today()->subDay()]);
    }
}
