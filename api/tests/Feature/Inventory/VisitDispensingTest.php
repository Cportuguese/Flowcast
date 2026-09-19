<?php

namespace Tests\Feature\Inventory;

use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\QueueEntry;
use App\Models\User;
use App\Models\VisitRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VisitDispensingTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_visit_with_medicines_stocks_them_out_against_the_visit(): void
    {
        $medicine = Medicine::factory()->create(['name' => 'Paracetamol', 'unit' => 'tablet']);
        $first = MedicineBatch::factory()->for($medicine)->expiresIn(30)->create(['quantity_received' => 4]);
        $second = MedicineBatch::factory()->for($medicine)->expiresIn(90)->create(['quantity_received' => 20]);
        $entry = QueueEntry::factory()->create();
        Sanctum::actingAs(User::factory()->personnel()->create());

        $this->postJson('/api/visits', [
            'queue_entry_id' => $entry->id,
            'complaint' => 'Headache',
            'diagnosis' => 'Tension headache',
            'medicines' => [['medicine_id' => $medicine->id, 'quantity' => 6]],
        ])->assertCreated()
            ->assertJsonCount(1, 'medicines')
            ->assertJsonPath('medicines.0.name', 'Paracetamol')
            ->assertJsonPath('medicines.0.quantity', 6);

        $visit = VisitRecord::where('queue_entry_id', $entry->id)->firstOrFail();
        $this->assertSame(0, $first->fresh()->quantity_remaining);
        $this->assertSame(18, $second->fresh()->quantity_remaining);
        $this->assertSame(2, $visit->inventoryLogs()->count());
        $this->assertSame(6, (int) $visit->inventoryLogs()->sum('quantity'));

        $this->getJson("/api/visits/{$entry->id}")->assertOk()->assertJsonPath('medicines.0.quantity', 6);
    }

    public function test_a_visit_is_not_saved_when_a_medicine_is_short(): void
    {
        $medicine = Medicine::factory()->create();
        MedicineBatch::factory()->for($medicine)->create(['quantity_received' => 2]);
        $entry = QueueEntry::factory()->create();
        Sanctum::actingAs(User::factory()->personnel()->create());

        $this->postJson('/api/visits', [
            'queue_entry_id' => $entry->id,
            'complaint' => 'Fever',
            'diagnosis' => 'Viral',
            'medicines' => [['medicine_id' => $medicine->id, 'quantity' => 3]],
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->assertDatabaseCount('visit_records', 0);
        $this->assertDatabaseCount('inventory_logs', 0);
    }

    public function test_a_visit_without_medicines_still_saves(): void
    {
        $entry = QueueEntry::factory()->create();
        Sanctum::actingAs(User::factory()->personnel()->create());

        $this->postJson('/api/visits', [
            'queue_entry_id' => $entry->id,
            'complaint' => 'Check-up',
            'diagnosis' => 'Well',
        ])->assertCreated()->assertJsonPath('medicines', []);
    }
}
