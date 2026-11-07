<?php

namespace Database\Factories;

use App\Models\QueueEntry;
use App\Models\User;
use App\Models\VisitRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VisitRecord>
 */
class VisitRecordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'queue_entry_id' => QueueEntry::factory(),
            // Mirrors the controller: patient is the queue entry's patient.
            'patient_id' => fn (array $attributes) => QueueEntry::find($attributes['queue_entry_id'])->patient_id,
            'created_by_id' => User::factory()->admin(),
            'complaint' => fake()->sentence(),
            'intervention' => fake()->sentence(),
            'remarks' => fake()->sentence(),
        ];
    }
}
