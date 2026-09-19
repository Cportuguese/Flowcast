<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryLog;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_in_creates_a_batch_and_log_and_raises_stock(): void
    {
        $medicine = Medicine::factory()->create();
        $nurse = User::factory()->personnel()->create();
        Sanctum::actingAs($nurse);

        $this->postJson('/api/inventory/stock-in', [
            'medicine_id' => $medicine->id,
            'quantity' => 50,
            'batch_number' => 'LOT-1',
            'expiry_date' => today()->addMonths(6)->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('medicine.stock', 50)
            ->assertJsonPath('medicine.status', 'ok')
            ->assertJsonPath('batch.batch_number', 'LOT-1');

        $this->assertSame(50, $medicine->fresh()->currentStock());
        $this->assertDatabaseHas('inventory_logs', [
            'medicine_id' => $medicine->id,
            'type' => 'stock_in',
            'quantity' => 50,
            'user_id' => $nurse->id,
        ]);
    }

    public function test_stock_in_rejects_archived_medicine_and_past_expiry(): void
    {
        $archived = Medicine::factory()->archived()->create();
        $active = Medicine::factory()->create();
        Sanctum::actingAs(User::factory()->personnel()->create());

        $this->postJson('/api/inventory/stock-in', [
            'medicine_id' => $archived->id,
            'quantity' => 10,
            'expiry_date' => today()->addYear()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('medicine_id');

        $this->postJson('/api/inventory/stock-in', [
            'medicine_id' => $active->id,
            'quantity' => 10,
            'expiry_date' => today()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('expiry_date');
    }

    public function test_stock_out_uses_earliest_expiring_batch_first_and_spans_batches(): void
    {
        $medicine = Medicine::factory()->create();
        // Created out of order on purpose: FIFO must sort by expiry, not by id.
        $later = MedicineBatch::factory()->for($medicine)->expiresIn(300)->create(['quantity_received' => 40]);
        $earlier = MedicineBatch::factory()->for($medicine)->expiresIn(30)->create(['quantity_received' => 25]);
        Sanctum::actingAs(User::factory()->personnel()->create());

        $this->postJson('/api/inventory/stock-out', [
            'medicine_id' => $medicine->id,
            'quantity' => 30,
            'notes' => 'Routine depletion',
        ])->assertOk()->assertJsonPath('medicine.stock', 35);

        $this->assertSame(0, $earlier->fresh()->quantity_remaining);
        $this->assertSame(35, $later->fresh()->quantity_remaining);

        $logs = InventoryLog::where('type', 'stock_out')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame([$earlier->id, $later->id], $logs->pluck('medicine_batch_id')->all());
        $this->assertSame([25, 5], $logs->pluck('quantity')->all());
    }

    public function test_stock_out_skips_expired_batches_and_rejects_shortfall_atomically(): void
    {
        $medicine = Medicine::factory()->create();
        MedicineBatch::factory()->for($medicine)->expired()->create(['quantity_received' => 100]);
        $usable = MedicineBatch::factory()->for($medicine)->create(['quantity_received' => 10]);
        Sanctum::actingAs(User::factory()->personnel()->create());

        $this->postJson('/api/inventory/stock-out', [
            'medicine_id' => $medicine->id,
            'quantity' => 11,
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->assertSame(10, $usable->fresh()->quantity_remaining);
        $this->assertDatabaseCount('inventory_logs', 0);
    }

    public function test_low_stock_lists_medicines_at_or_below_reorder_level(): void
    {
        $low = Medicine::factory()->create(['name' => 'Paracetamol', 'reorder_level' => 20]);
        MedicineBatch::factory()->for($low)->create(['quantity_received' => 25]);
        $fine = Medicine::factory()->create(['reorder_level' => 5]);
        MedicineBatch::factory()->for($fine)->create(['quantity_received' => 50]);
        $out = Medicine::factory()->create(['name' => 'Cetirizine']);
        Sanctum::actingAs(User::factory()->personnel()->create());

        $this->getJson('/api/inventory/low-stock')->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Cetirizine')
            ->assertJsonPath('0.status', 'out');

        $this->postJson('/api/inventory/stock-out', ['medicine_id' => $low->id, 'quantity' => 5])
            ->assertOk()
            ->assertJsonPath('medicine.status', 'low');

        $response = $this->getJson('/api/inventory/low-stock')->assertOk()->assertJsonCount(2);
        $this->assertEqualsCanonicalizing(['Cetirizine', 'Paracetamol'], collect($response->json())->pluck('name')->all());
    }

    public function test_expiring_soon_lists_batches_within_thirty_days_only(): void
    {
        $medicine = Medicine::factory()->create(['name' => 'Amoxicillin']);
        $soon = MedicineBatch::factory()->for($medicine)->expiresIn(10)->create();
        MedicineBatch::factory()->for($medicine)->expiresIn(60)->create();
        MedicineBatch::factory()->for($medicine)->expired()->create();
        MedicineBatch::factory()->for($medicine)->expiresIn(5)->create(['quantity_received' => 0]);
        Sanctum::actingAs(User::factory()->personnel()->create());

        $this->getJson('/api/inventory/expiring-soon')->assertOk()
            ->assertJsonPath('days', 30)
            ->assertJsonCount(1, 'batches')
            ->assertJsonPath('batches.0.id', $soon->id)
            ->assertJsonPath('batches.0.medicine.name', 'Amoxicillin')
            ->assertJsonPath('batches.0.days_until_expiry', 10);
    }

    public function test_expired_batches_are_excluded_from_stock_and_can_be_disposed_by_admin(): void
    {
        $medicine = Medicine::factory()->create();
        $expired = MedicineBatch::factory()->for($medicine)->expired()->create(['quantity_received' => 30]);
        MedicineBatch::factory()->for($medicine)->create(['quantity_received' => 10]);
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->getJson('/api/medicines')->assertOk()
            ->assertJsonPath('0.stock', 10)
            ->assertJsonPath('0.expired_quantity', 30);

        $this->getJson('/api/admin/inventory/expired')->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $expired->id);

        $this->postJson("/api/admin/inventory/batches/{$expired->id}/dispose")->assertOk()
            ->assertJsonPath('quantity_remaining', 0);

        $this->assertDatabaseHas('inventory_logs', [
            'medicine_batch_id' => $expired->id,
            'type' => 'disposed',
            'quantity' => 30,
            'user_id' => $admin->id,
        ]);
        $this->getJson('/api/admin/inventory/expired')->assertOk()->assertJsonCount(0);
    }

    public function test_only_expired_batches_can_be_disposed(): void
    {
        $batch = MedicineBatch::factory()->create();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/admin/inventory/batches/{$batch->id}/dispose")->assertUnprocessable();
        $this->assertSame(100, $batch->fresh()->quantity_remaining);
    }

    public function test_history_filters_by_type_medicine_and_date(): void
    {
        $paracetamol = Medicine::factory()->create(['name' => 'Paracetamol']);
        $other = Medicine::factory()->create();
        $in = InventoryLog::factory()->create(['medicine_id' => $paracetamol->id, 'type' => 'stock_in']);
        $out = InventoryLog::factory()->create(['medicine_id' => $paracetamol->id, 'type' => 'stock_out', 'quantity' => 3]);
        InventoryLog::factory()->create(['medicine_id' => $other->id, 'type' => 'stock_out']);
        $old = InventoryLog::factory()->create(['medicine_id' => $paracetamol->id]);
        $old->forceFill(['created_at' => now()->subMonths(2)])->save();
        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->getJson("/api/admin/inventory/history?medicine_id={$paracetamol->id}&type=stock_out")->assertOk();
        $this->assertSame([$out->id], collect($response->json())->pluck('id')->all());
        $this->assertSame('Paracetamol', $response->json('0.medicine.name'));

        $response = $this->getJson("/api/admin/inventory/history?medicine_id={$paracetamol->id}&date_from=".today()->subDay()->toDateString())->assertOk();
        $this->assertEqualsCanonicalizing([$in->id, $out->id], collect($response->json())->pluck('id')->all());

        $this->getJson('/api/admin/inventory/history?type=bogus')->assertUnprocessable();
    }
}
