<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\QueueEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'action' => 'queue.completed',
            'model_type' => 'QueueEntry',
            'model_id' => QueueEntry::factory(),
            'changes' => ['status' => 'done'],
        ];
    }
}
