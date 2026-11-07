<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Patients type their own ID number when they sign up, and the nurse finds
 * patients at the counter by that number. Unchecked, anyone could register
 * a classmate's number and have the classmate's visits recorded on — and
 * readable from — their own account. So a typed number is only a claim
 * until the nurse compares it with the patient's UDD ID card.
 */
class PatientIdentityTest extends TestCase
{
    use RefreshDatabase;

    private User $nurse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->nurse = User::factory()->nurse()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function healthRecord(array $overrides = []): array
    {
        return [
            'email' => 'alice@example.com',
            'first_name' => 'Alice',
            'middle_name' => 'Santos',
            'last_name' => 'Reyes',
            'birthdate' => '2005-02-14',
            'sex' => 'female',
            'home_address' => 'Brgy. Pantal, Dagupan City',
            'patient_type' => 'student',
            'course' => 'BSIT',
            'school_id' => '23-1057-651',
            ...$overrides,
        ];
    }

    public function test_a_self_entered_id_is_unchecked_until_the_nurse_checks_the_card(): void
    {
        $this->postJson('/api/register', [
            ...$this->healthRecord(),
            'password' => 'secret-password-1',
            'password_confirmation' => 'secret-password-1',
        ])->assertCreated();

        Sanctum::actingAs($this->nurse);

        // She sees enough to tell whether the person at the counter is the account holder.
        $found = $this->getJson('/api/admin/queue/lookup?school_id=23-1057-651')
            ->assertOk()
            ->assertJsonPath('patient.id_verified', false)
            ->assertJsonPath('patient.birthdate', '2005-02-14')
            ->assertJsonPath('patient.email', 'alice@example.com');

        $this->patchJson("/api/admin/patients/{$found->json('patient.id')}/verify-id")
            ->assertOk()
            ->assertJsonPath('id_verified', true);

        $this->getJson('/api/admin/queue/lookup?school_id=23-1057-651')->assertJsonPath('patient.id_verified', true);
        $this->assertDatabaseHas('audit_logs', ['action' => 'patient.id_verified', 'user_id' => $this->nurse->id]);
    }

    public function test_an_impostors_claim_is_released_and_the_real_owner_registered(): void
    {
        // Mallory signs up with Alice's name and ID number.
        $impostor = User::factory()->patient()->create(['first_name' => 'Alice', 'last_name' => 'Reyes', 'school_id' => '23-1057-651']);
        Sanctum::actingAs($this->nurse);

        // The real Alice is at the counter; the details do not match, so the
        // nurse takes the number off the account that claimed it…
        $this->patchJson("/api/admin/patients/{$impostor->id}/release-id")
            ->assertOk()
            ->assertJsonPath('school_id', null);
        $this->assertDatabaseHas('audit_logs', ['action' => 'patient.id_released', 'model_id' => $impostor->id]);

        // …and registers Alice with it, checked, since she took it from the card.
        $alice = $this->postJson('/api/admin/queue/walk-in', [
            'first_name' => 'Alice',
            'last_name' => 'Reyes',
            'course' => 'BSIT',
            'patient_type' => 'student',
            'school_id' => '23-1057-651',
        ])->assertCreated();

        $this->assertNotSame($impostor->id, $alice->json('patient.id'));
        $this->assertTrue(User::find($alice->json('patient.id'))->hasVerifiedSchoolId());

        // The impostor cannot claim the number back, and cannot book without one.
        Sanctum::actingAs($impostor->fresh());
        $this->patchJson('/api/user/profile', $this->healthRecord(['email' => $impostor->email]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_id']);
        $this->assertFalse($impostor->fresh()->hasCompleteHealthRecord());
    }

    public function test_a_checked_id_cannot_be_changed_from_the_app(): void
    {
        $patient = User::factory()->patient()->verifiedId()->create(['school_id' => '23-1057-651']);
        Sanctum::actingAs($patient);

        $this->patchJson('/api/user/profile', $this->healthRecord(['email' => $patient->email, 'school_id' => '23-1057-652']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.school_id.0', 'Your ID number was checked by the clinic, so only the nurse can change it.');

        // Everything else stays theirs to correct.
        $this->patchJson('/api/user/profile', $this->healthRecord(['email' => $patient->email, 'home_address' => 'Brgy. Lucao, Dagupan City']))
            ->assertOk();
        $this->assertTrue($patient->fresh()->hasVerifiedSchoolId());
    }

    public function test_an_unchecked_id_can_still_be_corrected_but_any_change_needs_checking_again(): void
    {
        $patient = User::factory()->patient()->create(['school_id' => '23-1057-651']);
        Sanctum::actingAs($patient);

        $this->patchJson('/api/user/profile', $this->healthRecord(['email' => $patient->email, 'school_id' => '23-1057-652']))->assertOk();

        // However it changes, a new number is unchecked.
        $checked = User::factory()->patient()->verifiedId()->create();
        $checked->school_id = '21-0001-001';
        $checked->save();
        $this->assertFalse($checked->fresh()->hasVerifiedSchoolId());
    }

    public function test_a_student_id_typed_without_dashes_is_registered_in_the_udd_format(): void
    {
        Sanctum::actingAs($this->nurse);

        $this->postJson('/api/admin/queue/walk-in', [
            'first_name' => 'Alice',
            'last_name' => 'Reyes',
            'course' => 'BSIT',
            'patient_type' => 'student',
            'school_id' => '231057651',
        ])->assertCreated()->assertJsonPath('patient.school_id', '23-1057-651');
    }

    public function test_only_a_patients_id_number_can_be_checked_or_released(): void
    {
        Sanctum::actingAs($this->nurse);

        $dentist = User::factory()->dentist()->create();
        $this->patchJson("/api/admin/patients/{$dentist->id}/verify-id")->assertNotFound();

        $noId = User::factory()->patient()->create(['school_id' => null]);
        $this->patchJson("/api/admin/patients/{$noId->id}/release-id")->assertUnprocessable();
    }

    public function test_staff_lists_show_whether_an_id_was_checked(): void
    {
        $patient = User::factory()->patient()->create();
        Sanctum::actingAs($this->nurse);

        $this->postJson('/api/queue/check-in', ['patient_id' => $patient->id])
            ->assertCreated()
            ->assertJsonPath('patient.id_verified', false);
    }

    public function test_the_live_queue_channel_is_the_head_nurses_only(): void
    {
        $authorize = Broadcast::driver()->getChannels()->get('queue.{date}.staff');

        $this->assertTrue($authorize($this->nurse, '2026-10-12'));
        $this->assertFalse($authorize(User::factory()->dentist()->create(), '2026-10-12'));
        $this->assertFalse($authorize(User::factory()->patient()->create(), '2026-10-12'));
    }
}
