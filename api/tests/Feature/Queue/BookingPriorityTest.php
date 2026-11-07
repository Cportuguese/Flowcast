<?php

namespace Tests\Feature\Queue;

use App\Http\Controllers\Api\QueueController;
use App\Models\Appointment;
use App\Models\MlModel;
use App\Models\QueueEntry;
use App\Models\User;
use App\Services\Ml\WaitTimePredictor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Nurse Portia sees her booked patients at their booked time, ahead of the
 * walk-ins — so "you'll be served at 9:20" is true — but only if they came
 * on time.
 */
class BookingPriorityTest extends TestCase
{
    use RefreshDatabase;

    private User $nurse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-12 09:00:00'));
        $this->nurse = User::factory()->nurse()->create();
    }

    private function walkIn(int $number, string $joined): QueueEntry
    {
        return QueueEntry::factory()->create([
            'queue_number' => $number,
            'queue_date' => today(),
            'status' => 'waiting',
            'source' => 'walk_in',
            'joined_at' => Carbon::parse("2026-10-12 {$joined}"),
        ]);
    }

    private function booked(int $number, string $joined, string $bookedFor): QueueEntry
    {
        $appointment = Appointment::factory()->create([
            'provider_id' => $this->nurse->id,
            'scheduled_at' => Carbon::parse("2026-10-12 {$bookedFor}"),
            'status' => 'checked_in',
        ]);

        return QueueEntry::factory()->create([
            'queue_number' => $number,
            'queue_date' => today(),
            'status' => 'waiting',
            'source' => 'appointment',
            'appointment_id' => $appointment->id,
            'patient_id' => $appointment->patient_id,
            'joined_at' => Carbon::parse("2026-10-12 {$joined}"),
        ]);
    }

    public function test_walk_ins_go_first_come_first_served_until_a_booking_is_due(): void
    {
        $first = $this->walkIn(1, '08:40');
        $this->walkIn(2, '08:45');
        $booked = $this->booked(3, '08:50', '09:20');
        Sanctum::actingAs($this->nurse);

        // 09:00 — the booking is not due yet.
        $this->postJson('/api/admin/queue/call-next')->assertOk()->assertJsonPath('id', $first->id);

        $this->patchJson("/api/admin/queue/{$first->id}/complete")->assertOk();

        // 09:20 — the booked patient's time has come.
        $this->travelTo(Carbon::parse('2026-10-12 09:20:00'));
        $this->postJson('/api/admin/queue/call-next')->assertOk()->assertJsonPath('id', $booked->id);
    }

    public function test_a_booked_patient_more_than_fifteen_minutes_late_joins_the_line(): void
    {
        $walkIn = $this->walkIn(1, '08:40');
        $this->booked(2, '08:56', '08:40');
        Sanctum::actingAs($this->nurse);

        $this->assertGreaterThan(QueueController::LATE_GRACE_MINUTES, 16);
        $this->postJson('/api/admin/queue/call-next')->assertOk()->assertJsonPath('id', $walkIn->id);
    }

    public function test_people_ahead_follows_the_order_patients_will_be_called(): void
    {
        $this->walkIn(1, '08:40');
        $this->walkIn(2, '08:45');
        $booked = $this->booked(3, '08:50', '09:00');
        $last = $this->walkIn(4, '08:55');

        Sanctum::actingAs($booked->patient);
        $this->getJson('/api/queue/mine')
            ->assertOk()
            ->assertJsonPath('people_ahead', 0)
            ->assertJsonPath('entry.booked_for', Carbon::parse('2026-10-12 09:00')->toIso8601String())
            // The visit form starts from what they booked for.
            ->assertJsonPath('entry.booking_reason', $booked->appointment->reason);

        Sanctum::actingAs($last->patient);
        $response = $this->getJson('/api/queue/mine')->assertOk()->assertJsonPath('people_ahead', 3);

        // The phone shows a clock time, not just minutes.
        $this->assertNotNull($response->json('estimated_call_at'));
    }

    public function test_the_tv_board_lists_up_next_in_the_order_patients_will_be_called(): void
    {
        $this->walkIn(1, '08:40');
        $this->walkIn(2, '08:45');
        $this->booked(3, '08:50', '09:00');

        $waiting = collect($this->getJson('/api/queue-board/today')->assertOk()->json('entries'))
            ->where('status', 'waiting')
            ->pluck('queue_number')
            ->all();

        $this->assertSame([3, 1, 2], $waiting);
    }

    public function test_a_checked_in_booking_is_settled_on_the_queue_not_marked_a_no_show(): void
    {
        $entry = $this->booked(1, '08:50', '08:40');
        Sanctum::actingAs($this->nurse);

        $this->patchJson("/api/appointments/{$entry->appointment_id}/no-show")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This patient is checked in and in the queue. If they are not there when called, skip them on the queue.');

        $this->patchJson("/api/admin/queue/{$entry->id}/skip")->assertOk();
        $this->assertSame('no_show', $entry->appointment->fresh()->status);
    }

    public function test_looking_up_an_id_finds_todays_booking_with_the_nurse(): void
    {
        $patient = User::factory()->patient()->create(['school_id' => '23-1057-651']);
        $booking = Appointment::factory()->for($patient, 'patient')->create([
            'provider_id' => $this->nurse->id,
            'scheduled_at' => Carbon::parse('2026-10-12 10:20'),
            'status' => 'booked',
        ]);
        // Seen by the dentist at the booked time, so never checked in to the queue.
        Appointment::factory()->for($patient, 'patient')->create([
            'provider_id' => User::factory()->dentist()->create()->id,
            'scheduled_at' => Carbon::parse('2026-10-12 08:30'),
            'status' => 'booked',
        ]);
        Sanctum::actingAs($this->nurse);

        $this->getJson('/api/admin/queue/lookup?school_id=23-1057-651')
            ->assertOk()
            ->assertJsonPath('patient.id', $patient->id)
            ->assertJsonPath('booking_today.id', $booking->id);

        // Typed without the dashes, it is still found.
        $this->getJson('/api/admin/queue/lookup?school_id=231057651')->assertJsonPath('patient.id', $patient->id);

        $this->postJson('/api/queue/check-in', ['patient_id' => $patient->id, 'appointment_id' => $booking->id])
            ->assertCreated()
            ->assertJsonPath('source', 'appointment');

        $this->assertSame('checked_in', $booking->fresh()->status);
    }

    public function test_bookings_due_soon_lengthen_a_walk_ins_estimate(): void
    {
        MlModel::factory()->active()->create();
        $predictor = app(WaitTimePredictor::class);

        $without = $predictor->predict(today(), 3)['minutes'];

        Appointment::factory()->count(2)->create([
            'provider_id' => $this->nurse->id,
            'scheduled_at' => Carbon::parse('2026-10-12 09:30'),
            'status' => 'booked',
        ]);
        // A dentist booking never touches the nurse's line.
        Appointment::factory()->create([
            'provider_id' => User::factory()->dentist()->create()->id,
            'scheduled_at' => Carbon::parse('2026-10-12 09:30'),
            'status' => 'booked',
        ]);
        $predictor->refresh();

        $this->assertGreaterThan($without, $predictor->predict(today(), 3)['minutes']);
    }
}
