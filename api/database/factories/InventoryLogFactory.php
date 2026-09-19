<?php

namespace Database\Factories;

use App\Models\InventoryLog;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryLog>
 */
class InventoryLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'medicine_id' => Medicine::factory(),
            'medicine_batch_id' => fn (array $attributes) => MedicineBatch::factory()->create(['medicine_id' => $attributes['medicine_id']])->id,
            'user_id' => User::factory()->personnel(),
            'type' => InventoryLog::TYPE_STOCK_IN,
            'quantity' => 100,
            'notes' => null,
        ];
    }
}
