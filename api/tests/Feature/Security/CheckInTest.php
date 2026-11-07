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
        Sanctum::actingAs(User::factory()->nurse()->create());

        $this->postJson('/api/queue/check-in')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['patient_id']);
    }

    public function test_staff_cannot_queue_a_staff_account(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs(User::factory()->nurse()->create());

        $this->postJson('/api/queue/check-in', ['patient_id' => $admin->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['patient_id']);
    }

    public function test_staff_can_check_in_a_patient(): void
    {
        $patient = User::factory()->patient()->create();
        Sanctum::actingAs(User::factory()->nurse()->create());

        $this->postJson('/api/queue/check-in', ['patient_id' => $patient->id])
            ->assertCreated()
            ->assertJsonPath('patient.id', $patient->id);
    }

    public function test_walk_in_creates_a_patient_account(): void
    {
        Sanctum::actingAs(User::factory()->nurse()->create());

        $response = $this->postJson('/api/admin/queue/walk-in', [
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'course' => 'BSTM',
        ])->assertCreated();

        $patient = User::find($response->json('patient.id'));
        $this->assertSame('patient', $patient->role);
        $this->assertSame('Ana Reyes', $patient->name);
        $this->assertSame('SIHM', $patient->department);
    }

    public function test_a_walk_in_needs_a_department(): void
    {
        Sanctum::actingAs(User::factory()->nurse()->create());

        $this->postJson('/api/admin/queue/walk-in', ['first_name' => 'Ana', 'last_name' => 'Reyes'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['department']);
    }

    public function test_a_registered_id_number_is_found_instead_of_duplicated(): void
    {
        $patient = User::factory()->patient()->create(['school_id' => '23-1057-651']);
        Sanctum::actingAs(User::factory()->nurse()->create());

        $this->getJson('/api/admin/queue/lookup?school_id=23-1057-651')
            ->assertOk()
            ->assertJsonPath('patient.id', $patient->id)
            ->assertJsonPath('in_queue', false);

        $this->postJson('/api/admin/queue/walk-in', [
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
            'course' => 'BSIT',
            'school_id' => '23-1057-651',
        ])->assertUnprocessable()->assertJsonValidationErrors(['school_id']);
    }

    public function test_the_dentist_cannot_check_anyone_into_the_nurses_queue(): void
    {
        $patient = User::factory()->patient()->create();
        Sanctum::actingAs(User::factory()->dentist()->create());

        $this->postJson('/api/queue/check-in', ['patient_id' => $patient->id])->assertForbidden();
    }

    public function test_only_a_confirmed_booking_with_the_nurse_for_today_checks_in(): void
    {
        $this->travelTo(now()->setTime(9, 0));

        $patient = User::factory()->patient()->create();
        $nurse = User::factory()->nurse()->create();
        $dentist = User::factory()->dentist()->create();
        Sanctum::actingAs($patient);

        $book = fn (array $attributes) => Appointment::factory()->for($patient, 'patient')->create([
            'provider_id' => $nurse->id,
            'scheduled_at' => now()->addHour(),
            'status' => 'booked',
            ...$attributes,
        ]);

        // Still under review: the nurse has not confirmed it.
        $this->postJson('/api/queue/check-in', ['appointment_id' => $book(['status' => 'pending'])->id])->assertUnprocessable();
        // The dentist sees patients at the booked time, without the queue.
        $this->postJson('/api/queue/check-in', ['appointment_id' => $book(['provider_id' => $dentist->id])->id])->assertUnprocessable();
        // Not today.
        $this->postJson('/api/queue/check-in', ['appointment_id' => $book(['scheduled_at' => now()->addDays(2)])->id])->assertUnprocessable();

        $this->postJson('/api/queue/check-in', ['appointment_id' => $book([])->id])
            ->assertCreated()
            ->assertJsonPath('source', 'appointment');
    }
}
