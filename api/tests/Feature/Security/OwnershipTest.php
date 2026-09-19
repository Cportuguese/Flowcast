<?php

namespace Tests\Feature\Security;

use App\Models\Appointment;
use App\Models\QueueEntry;
use App\Models\User;
use App\Models\VisitRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Role middleware says "a patient may call this"; these tests pin down
 * "...but only for their own rows".
 */
class OwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_patient_cannot_cancel_another_patients_appointment(): void
    {
        $other = Appointment::factory()->create();
        Sanctum::actingAs(User::factory()->patient()->create());

        $this->postJson("/api/appointments/{$other->id}/cancel")->assertForbidden();

        $this->assertSame('booked', $other->fresh()->status);
    }

    public function test_patient_can_cancel_their_own_appointment(): void
    {
        $me = User::factory()->patient()->create();
        $mine = Appointment::factory()->for($me, 'patient')->create();
        Sanctum::actingAs($me);

        $this->postJson("/api/appointments/{$mine->id}/cancel")->assertOk();

        $this->assertSame('cancelled', $mine->fresh()->status);
    }

    public function test_appointments_mine_only_lists_the_callers_rows(): void
    {
        $me = User::factory()->patient()->create();
        Appointment::factory()->for($me, 'patient')->create();
        Appointment::factory()->count(2)->create();
        Sanctum::actingAs($me);

        $response = $this->getJson('/api/appointments/mine')->assertOk();

        $this->assertCount(1, $response->json('upcoming'));
        $this->assertCount(0, $response->json('past'));
    }

    public function test_patient_cannot_view_another_patients_visit(): void
    {
        $visit = VisitRecord::factory()->create();
        Sanctum::actingAs(User::factory()->patient()->create());

        $this->getJson("/api/visits/{$visit->queue_entry_id}")->assertForbidden();
    }

    public function test_patient_can_view_their_own_visit_and_staff_can_view_any(): void
    {
        $visit = VisitRecord::factory()->create();

        Sanctum::actingAs($visit->patient);
        $this->getJson("/api/visits/{$visit->queue_entry_id}")->assertOk()->assertJsonPath('id', $visit->id);

        Sanctum::actingAs(User::factory()->personnel()->create());
        $this->getJson("/api/visits/{$visit->queue_entry_id}")->assertOk();
    }

    public function test_visits_mine_only_lists_the_callers_rows(): void
    {
        $me = User::factory()->patient()->create();
        $entry = QueueEntry::factory()->for($me, 'patient')->create();
        VisitRecord::factory()->for($entry, 'queueEntry')->create(['patient_id' => $me->id]);
        VisitRecord::factory()->count(2)->create();
        Sanctum::actingAs($me);

        $this->getJson('/api/visits/mine')->assertOk()->assertJsonCount(1);
    }

    public function test_queue_mine_only_returns_the_callers_entry(): void
    {
        $me = User::factory()->patient()->create();
        QueueEntry::factory()->create(['queue_number' => 1]);
        QueueEntry::factory()->for($me, 'patient')->create(['queue_number' => 2]);
        Sanctum::actingAs($me);

        $this->getJson('/api/queue/mine')
            ->assertOk()
            ->assertJsonPath('entry.patient.id', $me->id)
            ->assertJsonPath('people_ahead', 1);
    }
}
