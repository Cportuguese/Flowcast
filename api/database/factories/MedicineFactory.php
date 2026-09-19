<?php

namespace Database\Factories;

use App\Models\Medicine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Medicine>
 */
class MedicineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'category' => 'Analgesic',
            'unit' => 'tablet',
            'reorder_level' => 10,
            'reorder_quantity' => 100,
            'is_active' => true,
        ];
    }

    public function archived(): static
    {
        return $this->state(['is_active' => false]);
    }
}
