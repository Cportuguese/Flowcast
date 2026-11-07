<?php

namespace Tests\Feature\Queue;

use App\Models\Appointment;
use App\Models\QueueEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An appointment is checked in when its patient joins the queue, and must
 * finish when that queue entry does. Before this, nothing moved it past
 * "checked in", so the patient's app kept a finished or skipped visit under
 * Upcoming indefinitely.
 */
class AppointmentOutcomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_completing_the_entry_completes_the_appointment(): void
    {
        [$appointment, $entry] = $this->checkedInAppointment();
        Sanctum::actingAs(User::factory()->nurse()->create());

        $this->patchJson("/api/admin/queue/{$entry->id}/complete")->assertOk();

        $this->assertSame('completed', $appointment->fresh()->status);
    }

    public function test_skipping_the_entry_marks_the_appointment_a_no_show(): void
    {
        [$appointment, $entry] = $this->checkedInAppointment();
        Sanctum::actingAs(User::factory()->nurse()->create());

        $this->patchJson("/api/admin/queue/{$entry->id}/skip")->assertOk();

        $this->assertSame('no_show', $appointment->fresh()->status);
    }

    public function test_an_appointment_already_settled_some_other_way_is_left_alone(): void
    {
        [$appointment, $entry] = $this->checkedInAppointment();
        $appointment->update(['status' => 'cancelled']);
        Sanctum::actingAs(User::factory()->nurse()->create());

        $this->patchJson("/api/admin/queue/{$entry->id}/complete")->assertOk();

        $this->assertSame('cancelled', $appointment->fresh()->status);
    }

    public function test_settled_appointments_are_past_even_when_their_slot_is_still_ahead(): void
    {
        $patient = User::factory()->patient()->create();
        $later = now()->addHour();

        foreach (['booked', 'checked_in', 'completed', 'no_show', 'cancelled'] as $status) {
            Appointment::factory()->for($patient, 'patient')->create(['status' => $status, 'scheduled_at' => $later]);
        }
        Sanctum::actingAs($patient);

        $response = $this->getJson('/api/appointments/mine')->assertOk();

        $this->assertEqualsCanonicalizing(['booked', 'checked_in'], array_column($response->json('upcoming'), 'status'));
        $this->assertEqualsCanonicalizing(['completed', 'no_show', 'cancelled'], array_column($response->json('past'), 'status'));
    }

    /**
     * Rows left at "checked in" before the fix are settled once, on migrate.
     */
    public function test_migration_settles_appointments_left_checked_in_before_the_fix(): void
    {
        [$finished, $finishedEntry] = $this->checkedInAppointment();
        [$skipped, $skippedEntry] = $this->checkedInAppointment();
        [$stillWaiting] = $this->checkedInAppointment();
        $finishedEntry->update(['status' => 'done']);
        $skippedEntry->update(['status' => 'skipped']);

        $migration = require glob(database_path('migrations/*_settle_appointments_left_checked_in.php'))[0];
        $migration->up();

        $this->assertSame('completed', $finished->fresh()->status);
        $this->assertSame('no_show', $skipped->fresh()->status);
        $this->assertSame('checked_in', $stillWaiting->fresh()->status);
    }

    /**
     * @return array{0: Appointment, 1: QueueEntry}
     */
    private function checkedInAppointment(): array
    {
        $patient = User::factory()->patient()->create();
        $appointment = Appointment::factory()->for($patient, 'patient')->create([
            'status' => 'checked_in',
            'scheduled_at' => now()->addHour(),
        ]);
        $entry = QueueEntry::factory()->for($patient, 'patient')->create([
            'source' => 'appointment',
            'appointment_id' => $appointment->id,
            'status' => 'in_consultation',
            'called_at' => now(),
        ]);

        return [$appointment, $entry];
    }
}
