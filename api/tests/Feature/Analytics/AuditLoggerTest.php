<?php

namespace Tests\Feature\Analytics;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\ClinicSchedule;
use App\Models\QueueEntry;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_logger_writes_a_row_with_the_given_fields(): void
    {
        $entry = QueueEntry::factory()->create();
        $user = User::factory()->personnel()->create();

        app(AuditLogger::class)->log($user->id, 'queue.completed', $entry, ['status' => 'done']);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'queue.completed',
            'model_type' => 'QueueEntry',
            'model_id' => $entry->id,
        ]);
        $this->assertSame(['status' => 'done'], AuditLog::latest()->first()->changes);
    }

    public function test_booking_an_appointment_is_logged(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-14 08:00:00'));
        $scheduleDate = today()->addDay();

        ClinicSchedule::factory()->create([
            'day_of_week' => $scheduleDate->dayOfWeek,
            'start_time' => '08:00',
            'end_time' => '17:00',
            'slot_minutes' => 30,
            'capacity' => 2,
            'is_active' => true,
        ]);

        $patient = User::factory()->patient()->create();
        Sanctum::actingAs($patient);

        $this->postJson('/api/appointments', [
            'date' => $scheduleDate->toDateString(),
            'time' => '09:00',
            'reason' => 'Checkup',
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $patient->id,
            'action' => 'appointment.booked',
            'model_type' => 'Appointment',
        ]);
    }

    public function test_cancelling_an_appointment_is_logged(): void
    {
        $patient = User::factory()->patient()->create();
        $appointment = Appointment::factory()->for($patient, 'patient')->create();
        Sanctum::actingAs($patient);

        $this->postJson("/api/appointments/{$appointment->id}/cancel")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $patient->id,
            'action' => 'appointment.cancelled',
            'model_type' => 'Appointment',
            'model_id' => $appointment->id,
        ]);
    }

    public function test_marking_a_no_show_is_logged(): void
    {
        $appointment = Appointment::factory()->create();
        Sanctum::actingAs(User::factory()->personnel()->create());

        $this->patchJson("/api/appointments/{$appointment->id}/no-show")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'appointment.no_show',
            'model_type' => 'Appointment',
            'model_id' => $appointment->id,
        ]);
    }

    public function test_walk_in_check_in_is_logged(): void
    {
        Sanctum::actingAs(User::factory()->personnel()->create());

        $this->postJson('/api/admin/queue/walk-in', ['name' => 'Walk In'])->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['action' => 'queue.walk_in', 'model_type' => 'QueueEntry']);
    }

    public function test_calling_completing_and_skipping_a_queue_entry_are_logged(): void
    {
        Sanctum::actingAs(User::factory()->personnel()->create());

        $waiting = QueueEntry::factory()->create(['status' => 'waiting', 'queue_date' => today()]);
        $this->postJson('/api/admin/queue/call-next')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'queue.called', 'model_id' => $waiting->id]);

        $this->patchJson("/api/admin/queue/{$waiting->id}/complete")->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'queue.completed', 'model_id' => $waiting->id]);

        $other = QueueEntry::factory()->create(['status' => 'waiting', 'queue_date' => today()]);
        $this->patchJson("/api/admin/queue/{$other->id}/skip")->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'queue.skipped', 'model_id' => $other->id]);
    }

    public function test_creating_a_visit_record_is_logged(): void
    {
        $entry = QueueEntry::factory()->create();
        Sanctum::actingAs(User::factory()->personnel()->create());

        $this->postJson('/api/visits', [
            'queue_entry_id' => $entry->id,
            'complaint' => 'Headache',
            'diagnosis' => 'Tension headache',
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['action' => 'visit.created', 'model_type' => 'VisitRecord']);
    }

    public function test_updating_a_schedule_is_logged(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/admin/schedules', [
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '17:00',
            'slot_minutes' => 15,
            'capacity' => 1,
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'schedule.updated', 'model_type' => 'ClinicSchedule']);
    }

    public function test_creating_and_deleting_an_event_day_are_logged(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->postJson('/api/admin/event-days', [
            'date' => today()->addMonth()->toDateString(),
            'label' => 'Enrollment week',
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['action' => 'event_day.created', 'model_type' => 'EventDay']);

        $this->deleteJson("/api/admin/event-days/{$response->json('id')}")->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'event_day.deleted', 'model_type' => 'EventDay']);
    }
}
