<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A patient completing or correcting their UDD Health Services record.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private function record(array $overrides = []): array
    {
        return [
            'email' => 'juan@example.com',
            'first_name' => 'Juan',
            'middle_name' => 'Santiago',
            'last_name' => 'Ramos',
            'birthdate' => '2004-03-04',
            'sex' => 'male',
            'home_address' => 'Brgy. Bonuan Gueset, Dagupan City',
            'patient_type' => 'student',
            'course' => 'BSCrim',
            'school_id' => '24-0213-118',
            ...$overrides,
        ];
    }

    public function test_a_walk_in_completes_their_record_and_can_then_book(): void
    {
        $patient = User::factory()->patient()->withoutHealthRecord()->create();
        $this->assertFalse($patient->hasCompleteHealthRecord());
        Sanctum::actingAs($patient);

        $this->patchJson('/api/user/profile', $this->record())
            ->assertOk()
            ->assertJsonPath('name', 'Juan Santiago Ramos')
            ->assertJsonPath('department', 'SOC')
            ->assertJsonPath('birthdate', '2004-03-04');

        $this->assertTrue($patient->fresh()->hasCompleteHealthRecord());
        $this->assertDatabaseHas('audit_logs', ['action' => 'profile.updated', 'user_id' => $patient->id]);
    }

    public function test_the_audit_log_names_the_fields_not_their_values(): void
    {
        $patient = User::factory()->patient()->create();
        Sanctum::actingAs($patient);

        $this->patchJson('/api/user/profile', $this->record(['home_address' => 'Secret Street 1, Calasiao']))->assertOk();

        $changes = AuditLog::where('action', 'profile.updated')->latest('id')->first()->changes;
        $this->assertContains('home_address', $changes['fields']);
        $this->assertStringNotContainsString('Secret Street', json_encode($changes));
    }

    public function test_a_patient_keeps_their_own_id_number_but_cannot_take_anothers(): void
    {
        User::factory()->patient()->create(['school_id' => '22-0412-118']);
        $patient = User::factory()->patient()->create(['school_id' => '24-0213-118']);
        Sanctum::actingAs($patient);

        $this->patchJson('/api/user/profile', $this->record())->assertOk();

        $this->patchJson('/api/user/profile', $this->record(['school_id' => '22-0412-118']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_id']);
    }

    public function test_staff_have_no_patient_record_to_edit(): void
    {
        Sanctum::actingAs(User::factory()->dentist()->create());

        $this->patchJson('/api/user/profile', $this->record())->assertForbidden();
    }
}
