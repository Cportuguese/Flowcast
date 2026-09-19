<?php

namespace Tests\Feature\Security;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckInTest extends TestCase
{
    use RefreshDatabase;

    public function test_patient_cannot_check_in_someone_else(): void
    {
        $other = User::factory()->patient()->create();
        Sanctum::actingAs(User::factory()->patient()->create());

        $this->postJson('/api/queue/check-in', ['patient_id' => $other->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['patient_id']);
    }

    public function test_patient_checks_themselves_in(): void
    {
        $me = User::factory()->patient()->create();
        Sanctum::actingAs($me);

        $this->postJson('/api/queue/check-in')
            ->assertCreated()
            ->assertJsonPath('patient.id', $me->id);
    }

    public function test_patient_cannot_check_in_with_another_patients_appointment(): void
    {
        $theirs = Appointment::factory()->create();
        Sanctum::actingAs(User::factory()->patient()->create());

        $this->postJson('/api/queue/check-in', ['appointment_id' => $theirs->id])
            ->assertUnprocessable();

        $this->assertSame('booked', $theirs->fresh()->status);
    }

    public function test_staff_must_name_a_patient(): void
    {
        Sanctum::actingAs(User::factory()->personnel()->create());

        $this->postJson('/api/queue/check-in')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['patient_id']);
    }

    public function test_staff_cannot_queue_a_staff_account(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs(User::factory()->personnel()->create());

        $this->postJson('/api/queue/check-in', ['patient_id' => $admin->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['patient_id']);
    }

    public function test_staff_can_check_in_a_patient(): void
    {
        $patient = User::factory()->patient()->create();
        Sanctum::actingAs(User::factory()->personnel()->create());

        $this->postJson('/api/queue/check-in', ['patient_id' => $patient->id])
            ->assertCreated()
            ->assertJsonPath('patient.id', $patient->id);
    }

    public function test_walk_in_creates_a_patient_account(): void
    {
        Sanctum::actingAs(User::factory()->personnel()->create());

        $response = $this->postJson('/api/admin/queue/walk-in', ['name' => 'Walk In'])->assertCreated();

        $this->assertSame('patient', User::find($response->json('patient.id'))->role);
    }
}
