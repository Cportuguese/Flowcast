<?php

namespace Tests\Feature\Inventory;

use App\Models\Medicine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MedicineCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_add_a_medicine_and_it_is_audit_logged(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/medicines', [
            'name' => 'Paracetamol 500mg',
            'category' => 'Analgesic',
            'unit' => 'tablet',
            'reorder_level' => 50,
            'reorder_quantity' => 200,
        ])->assertCreated()
            ->assertJsonPath('name', 'Paracetamol 500mg')
            ->assertJsonPath('stock', 0)
            ->assertJsonPath('status', 'out');

        $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'action' => 'medicine.created', 'model_type' => 'Medicine']);
    }

    public function test_medicine_names_are_unique(): void
    {
        Medicine::factory()->create(['name' => 'Ibuprofen']);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/admin/medicines', ['name' => 'Ibuprofen', 'unit' => 'tablet', 'reorder_level' => 5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_archiving_hides_a_medicine_from_staff_unless_asked_for(): void
    {
        $medicine = Medicine::factory()->create();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/admin/medicines/{$medicine->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('is_active', false);

        $this->getJson('/api/medicines')->assertOk()->assertJsonCount(0);
        $this->getJson('/api/medicines?include_inactive=1')->assertOk()->assertJsonCount(1);
    }

    public function test_update_only_logs_actual_changes(): void
    {
        $medicine = Medicine::factory()->create(['reorder_level' => 10]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/admin/medicines/{$medicine->id}", ['reorder_level' => 10])->assertOk();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'medicine.updated']);

        $this->patchJson("/api/admin/medicines/{$medicine->id}", ['reorder_level' => 30])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'medicine.updated', 'model_id' => $medicine->id]);
    }
}
