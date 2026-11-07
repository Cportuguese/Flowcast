<?php

namespace Tests\Feature\Visits;

use App\Models\Appointment;
use App\Models\QueueEntry;
use App\Models\User;
use App\Models\VisitRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The dentist and the school physician see booked patients at their booked
 * time, without a queue number; their visit record hangs off the booking.
 */
class AppointmentVisitTest extends TestCase
{
    use RefreshDatabase;

    private function record(array $overrides = []): array
    {
        return [
            'complaint' => 'Toothache',
            'intervention' => 'Tooth extraction under local anesthesia.',
            'remarks' => 'Bite on gauze for 30 minutes.',
            ...$overrides,
        ];
    }

    public function test_the_dentist_records_a_visit_and_the_booking_completes(): void
    {
        $dentist = User::factory()->dentist()->create();
        $appointment = Appointment::factory()->create(['provider_id' => $dentist->id, 'status' => 'booked', 'scheduled_at' => now()]);
        Sanctum::actingAs($dentist);

        $response = $this->postJson('/api/visits', $this->record(['appointment_id' => $appointment->id]))
            ->assertCreated()
            ->assertJsonPath('appointment_id', $appointment->id)
            ->assertJsonPath('queue_entry_id', null)
            ->assertJsonPath('intervention', 'Tooth extraction under local anesthesia.')
            ->assertJsonPath('remarks', 'Bite on gauze for 30 minutes.')
            ->assertJsonPath('created_by', "Dr. {$dentist->name}");

        $this->assertSame('completed', $appointment->fresh()->status);
        $this->assertSame($appointment->patient_id, VisitRecord::find($response->json('id'))->patient_id);
    }

    public function test_the_dentist_cannot_record_someone_elses_patient_or_the_queue(): void
    {
        $dentist = User::factory()->dentist()->create();
        $physicians = Appointment::factory()->create(['provider_id' => User::factory()->physician()->create()->id]);
        $entry = QueueEntry::factory()->create();
        Sanctum::actingAs($dentist);

        $this->postJson('/api/visits', $this->record(['appointment_id' => $physicians->id]))->assertForbidden();
        $this->postJson('/api/visits', $this->record(['queue_entry_id' => $entry->id]))->assertForbidden();
    }

    public function test_a_booking_is_recorded_only_once_and_only_when_confirmed(): void
    {
        $dentist = User::factory()->dentist()->create();
        $pending = Appointment::factory()->create(['provider_id' => $dentist->id, 'status' => 'pending']);
        $confirmed = Appointment::factory()->create(['provider_id' => $dentist->id, 'status' => 'booked', 'scheduled_at' => now()]);
        Sanctum::actingAs($dentist);

        $this->postJson('/api/visits', $this->record(['appointment_id' => $pending->id]))->assertUnprocessable();

        $this->postJson('/api/visits', $this->record(['appointment_id' => $confirmed->id]))->assertCreated();
        $this->postJson('/api/visits', $this->record(['appointment_id' => $confirmed->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['appointment_id']);
    }

    public function test_a_visit_comes_from_a_queue_entry_or_a_booking_not_both(): void
    {
        $nurse = User::factory()->nurse()->create();
        Sanctum::actingAs($nurse);

        $this->postJson('/api/visits', $this->record())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['queue_entry_id', 'appointment_id']);

        $this->postJson('/api/visits', $this->record([
            'queue_entry_id' => QueueEntry::factory()->create()->id,
            'appointment_id' => Appointment::factory()->create(['provider_id' => User::factory()->dentist()->create()->id])->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['queue_entry_id']);
    }

    public function test_an_intervention_is_required(): void
    {
        Sanctum::actingAs(User::factory()->nurse()->create());

        $this->postJson('/api/visits', $this->record([
            'queue_entry_id' => QueueEntry::factory()->create()->id,
            'intervention' => '',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['intervention']);
    }

    public function test_a_booking_is_recorded_on_its_day_not_ahead_of_it(): void
    {
        $dentist = User::factory()->dentist()->create();
        $nextWeek = Appointment::factory()->create(['provider_id' => $dentist->id, 'status' => 'booked', 'scheduled_at' => now()->addWeek()]);
        Sanctum::actingAs($dentist);

        $this->postJson('/api/visits', $this->record(['appointment_id' => $nextWeek->id]))->assertUnprocessable();
        $this->assertSame('booked', $nextWeek->fresh()->status);
    }

    public function test_the_patient_reads_their_record_by_its_own_id(): void
    {
        $dentist = User::factory()->dentist()->create();
        $appointment = Appointment::factory()->create(['provider_id' => $dentist->id, 'status' => 'booked', 'scheduled_at' => now()]);
        Sanctum::actingAs($dentist);
        $visitId = $this->postJson('/api/visits', $this->record(['appointment_id' => $appointment->id]))->json('id');

        Sanctum::actingAs($appointment->patient);

        $this->getJson('/api/visits/mine')
            ->assertOk()
            ->assertJsonPath('0.id', $visitId)
            ->assertJsonPath('0.intervention', 'Tooth extraction under local anesthesia.')
            ->assertJsonPath('0.seen_by_title', 'Dentist');

        $this->getJson("/api/visits/{$visitId}")->assertOk()->assertJsonPath('complaint', 'Toothache');
    }
}
