<?php

namespace Tests\Feature\Appointments;

use App\Mail\BookingCode;
use App\Models\Appointment;
use App\Models\EventDay;
use App\Models\ProviderSchedule;
use App\Models\User;
use App\Services\BookingOtp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Booking with one of the three professionals: slot -> emailed code ->
 * "under review" -> the head nurse confirms or declines.
 */
class BookingTest extends TestCase
{
    use RefreshDatabase;

    private User $dentist;

    private User $patient;

    /** Tuesday 2026-10-13, 08:00 — a dentist day. */
    private Carbon $tuesday;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->tuesday = Carbon::parse('2026-10-13');
        $this->travelTo($this->tuesday->copy()->subDay()->setTime(8, 0));

        $this->dentist = User::factory()->dentist()->create(['name' => 'Marco Villanueva']);
        ProviderSchedule::factory()->for($this->dentist)->create([
            'day_of_week' => 2,
            'start_time' => '08:00',
            'end_time' => '17:00',
            'slot_minutes' => 30,
            'capacity' => 1,
        ]);

        $this->patient = User::factory()->patient()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function booking(array $overrides = []): array
    {
        return [
            'provider_id' => $this->dentist->id,
            'date' => $this->tuesday->toDateString(),
            'time' => '09:00',
            'reason' => 'Tooth extraction',
            ...$overrides,
        ];
    }

    /** Ask for a code and read it out of the email that was "sent". */
    private function requestCode(array $booking): string
    {
        $this->postJson('/api/appointments/otp', $booking)->assertOk();

        $code = null;
        Mail::assertSent(BookingCode::class, function (BookingCode $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        return $code;
    }

    public function test_patients_see_the_bookable_professionals_with_their_days(): void
    {
        Sanctum::actingAs($this->patient);

        $this->getJson('/api/providers')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.display_name', 'Dr. Marco Villanueva')
            ->assertJsonPath('0.job_title', 'Dentist')
            ->assertJsonPath('0.schedule.0.day_of_week', 2)
            ->assertJsonPath('0.schedule.0.start_time', '08:00');
    }

    public function test_slots_follow_the_professionals_own_schedule(): void
    {
        Sanctum::actingAs($this->patient);

        $tuesday = $this->getJson("/api/appointments/available-slots?provider_id={$this->dentist->id}&date=2026-10-13")->assertOk();
        $this->assertCount(18, $tuesday->json('slots'));
        $this->assertSame('08:00', $tuesday->json('slots.0.time'));

        // The dentist is not in on Wednesdays.
        $this->getJson("/api/appointments/available-slots?provider_id={$this->dentist->id}&date=2026-10-14")
            ->assertOk()
            ->assertJsonCount(0, 'slots');
    }

    public function test_the_calendar_shows_which_days_can_be_booked(): void
    {
        // Thursday is also a dentist day; make it fully booked.
        ProviderSchedule::factory()->for($this->dentist)->create(['day_of_week' => 4, 'start_time' => '08:00', 'end_time' => '09:00', 'slot_minutes' => 30]);
        foreach (['08:00', '08:30'] as $time) {
            Appointment::factory()->create(['provider_id' => $this->dentist->id, 'scheduled_at' => Carbon::parse("2026-10-15 {$time}"), 'status' => 'booked']);
        }
        EventDay::create(['date' => '2026-10-20', 'label' => 'Intramurals']);

        Sanctum::actingAs($this->patient);

        $days = collect($this->getJson("/api/appointments/calendar?provider_id={$this->dentist->id}&month=2026-10")
            ->assertOk()
            ->assertJsonCount(31, 'days')
            ->json('days'))->keyBy('date');

        $this->assertSame('past', $days['2026-10-06']['status']);
        $this->assertSame('open', $days['2026-10-13']['status']);
        $this->assertSame(18, $days['2026-10-13']['free_slots']);
        $this->assertSame('closed', $days['2026-10-14']['status']);  // Wednesday
        $this->assertSame('full', $days['2026-10-15']['status']);
        $this->assertSame('event', $days['2026-10-20']['status']);
        $this->assertSame('Intramurals', $days['2026-10-20']['event']);
    }

    public function test_a_day_whose_times_have_all_gone_by_is_past_not_full(): void
    {
        // The dentist's last Tuesday slot starts at 16:30.
        $this->travelTo($this->tuesday->copy()->setTime(16, 45));
        Sanctum::actingAs($this->patient);

        $days = collect($this->getJson("/api/appointments/calendar?provider_id={$this->dentist->id}&month=2026-10")
            ->assertOk()
            ->json('days'))->keyBy('date');

        $this->assertSame('past', $days['2026-10-13']['status']);
        $this->assertSame(0, $days['2026-10-13']['free_slots']);
    }

    public function test_the_right_code_sends_the_booking_for_review(): void
    {
        Sanctum::actingAs($this->patient);

        $code = $this->requestCode($this->booking());

        $this->postJson('/api/appointments', [...$this->booking(), 'code' => $code])
            ->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('provider.display_name', 'Dr. Marco Villanueva')
            ->assertJsonPath('goes_through_queue', false);

        $this->assertDatabaseHas('appointments', ['patient_id' => $this->patient->id, 'provider_id' => $this->dentist->id, 'status' => 'pending']);
        Mail::assertSent(BookingCode::class, fn (BookingCode $mail) => $mail->hasTo($this->patient->email));
    }

    public function test_the_code_is_stored_hashed_never_in_plain_text(): void
    {
        Sanctum::actingAs($this->patient);

        $code = $this->requestCode($this->booking());

        $stored = cache()->get("booking-otp:{$this->patient->id}");
        $this->assertNotSame($code, $stored['hash']);
        $this->assertStringNotContainsString($code, json_encode($stored));
    }

    public function test_a_wrong_code_books_nothing_and_five_wrong_codes_lock_it(): void
    {
        Sanctum::actingAs($this->patient);

        $code = $this->requestCode($this->booking());
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->postJson('/api/appointments', [...$this->booking(), 'code' => $wrong])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'That code is not right. 4 tries left.');

        // Spaced out so the route's own limit (5 a minute) does not answer
        // first — this is about the code's lock, not the rate limit.
        $this->travel(61)->seconds();
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/appointments', [...$this->booking(), 'code' => $wrong])->assertUnprocessable();
        }
        $this->travel(61)->seconds();

        // Even the right code no longer works once the code is locked.
        $this->postJson('/api/appointments', [...$this->booking(), 'code' => $code])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Too many wrong codes. Ask for a new one.');
        $this->assertSame(0, Appointment::count());
    }

    public function test_a_code_only_works_for_the_slot_it_was_sent_for(): void
    {
        Sanctum::actingAs($this->patient);

        $code = $this->requestCode($this->booking(['time' => '09:00']));

        $this->postJson('/api/appointments', [...$this->booking(['time' => '10:00']), 'code' => $code])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'That code was sent for a different time. Ask for a new one.');
    }

    public function test_a_code_expires_after_ten_minutes(): void
    {
        Sanctum::actingAs($this->patient);

        $code = $this->requestCode($this->booking());
        $this->travel(BookingOtp::TTL_SECONDS + 1)->seconds();

        $this->postJson('/api/appointments', [...$this->booking(), 'code' => $code])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'That code has expired. Ask for a new one.');
    }

    public function test_a_new_code_needs_a_minute_between_requests(): void
    {
        Sanctum::actingAs($this->patient);

        $this->postJson('/api/appointments/otp', $this->booking())->assertOk();
        $this->postJson('/api/appointments/otp', $this->booking())->assertStatus(429);

        $this->travel(BookingOtp::RESEND_AFTER_SECONDS)->seconds();
        $this->postJson('/api/appointments/otp', $this->booking())->assertOk();
    }

    public function test_an_incomplete_health_record_must_be_completed_first(): void
    {
        Sanctum::actingAs(User::factory()->patient()->withoutHealthRecord()->create());

        $this->postJson('/api/appointments/otp', $this->booking())
            ->assertUnprocessable()
            ->assertJsonPath('code', 'health_record_incomplete');

        Mail::assertNothingSent();
    }

    public function test_slots_outside_the_schedule_or_already_taken_are_refused(): void
    {
        Sanctum::actingAs($this->patient);

        $this->postJson('/api/appointments/otp', $this->booking(['time' => '09:10']))->assertUnprocessable();
        $this->postJson('/api/appointments/otp', $this->booking(['date' => '2026-10-14']))->assertUnprocessable();

        // A pending booking already holds the 09:00 place.
        Appointment::factory()->create([
            'provider_id' => $this->dentist->id,
            'scheduled_at' => $this->tuesday->copy()->setTime(9, 0),
            'status' => 'pending',
        ]);
        $this->postJson('/api/appointments/otp', $this->booking())->assertStatus(409);
    }

    public function test_one_booking_per_professional_per_day(): void
    {
        Appointment::factory()->for($this->patient, 'patient')->create([
            'provider_id' => $this->dentist->id,
            'scheduled_at' => $this->tuesday->copy()->setTime(14, 0),
            'status' => 'booked',
        ]);
        Sanctum::actingAs($this->patient);

        $this->postJson('/api/appointments/otp', $this->booking())
            ->assertUnprocessable()
            ->assertJsonPath('message', 'You already have a booking with Dr. Marco Villanueva on that day.');
    }

    public function test_the_head_nurse_confirms_or_declines_bookings_under_review(): void
    {
        $nurse = User::factory()->nurse()->create();
        $first = Appointment::factory()->create(['provider_id' => $this->dentist->id, 'scheduled_at' => $this->tuesday->copy()->setTime(9, 0), 'status' => 'pending']);
        $second = Appointment::factory()->create(['provider_id' => $this->dentist->id, 'scheduled_at' => $this->tuesday->copy()->setTime(10, 0), 'status' => 'pending']);
        Sanctum::actingAs($nurse);

        $this->patchJson("/api/appointments/{$first->id}/approve")
            ->assertOk()
            ->assertJsonPath('status', 'booked');

        $this->patchJson("/api/appointments/{$second->id}/decline", ['review_note' => 'The dentist is at a seminar that morning.'])
            ->assertOk()
            ->assertJsonPath('status', 'declined')
            ->assertJsonPath('review_note', 'The dentist is at a seminar that morning.');

        $this->assertSame($nurse->id, $first->fresh()->reviewed_by_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'appointment.approved', 'model_id' => $first->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'appointment.declined', 'model_id' => $second->id]);

        // Already reviewed: nothing more to decide.
        $this->patchJson("/api/appointments/{$first->id}/approve")->assertUnprocessable();
    }

    public function test_the_dentist_cannot_review_bookings(): void
    {
        $pending = Appointment::factory()->create(['provider_id' => $this->dentist->id, 'status' => 'pending']);
        Sanctum::actingAs($this->dentist);

        $this->patchJson("/api/appointments/{$pending->id}/approve")->assertForbidden();
    }

    public function test_a_confirmed_patient_sees_their_time_and_who_is_ahead(): void
    {
        $at = fn (string $time) => Carbon::parse("2026-10-13 {$time}");
        $book = fn (string $time, string $status = 'booked', ?User $patient = null) => Appointment::factory()->create([
            'patient_id' => ($patient ?? User::factory()->patient()->create())->id,
            'provider_id' => $this->dentist->id,
            'scheduled_at' => $at($time),
            'status' => $status,
        ]);

        $book('08:00');
        $book('08:30');
        $book('09:30', 'pending');    // not confirmed: nobody is behind it yet
        $book('10:00', 'cancelled');  // gave up the slot
        $book('11:00');               // after mine
        $mine = $book('10:30', 'booked', $this->patient);

        Sanctum::actingAs($this->patient);

        $this->getJson('/api/appointments/mine')
            ->assertOk()
            ->assertJsonPath('upcoming.0.id', $mine->id)
            ->assertJsonPath('upcoming.0.people_ahead', 2);
    }

    public function test_a_confirmed_booking_stays_upcoming_for_the_rest_of_its_day(): void
    {
        $confirmed = Appointment::factory()->for($this->patient, 'patient')->create([
            'provider_id' => $this->dentist->id,
            'scheduled_at' => $this->tuesday->copy()->setTime(9, 0),
            'status' => 'booked',
        ]);
        $unreviewed = Appointment::factory()->for($this->patient, 'patient')->create([
            'provider_id' => User::factory()->physician()->create()->id,
            'scheduled_at' => $this->tuesday->copy()->setTime(9, 30),
            'status' => 'pending',
        ]);
        Sanctum::actingAs($this->patient);

        // 09:40: the patient is a few minutes late, not gone.
        $this->travelTo($this->tuesday->copy()->setTime(9, 40));
        $response = $this->getJson('/api/appointments/mine')->assertOk();
        $this->assertSame([$confirmed->id], array_column($response->json('upcoming'), 'id'));
        // A request nobody confirmed in time is over.
        $this->assertSame([$unreviewed->id], array_column($response->json('past'), 'id'));

        // The next day it is history.
        $this->travelTo($this->tuesday->copy()->addDay()->setTime(8, 0));
        $this->assertSame([], $this->getJson('/api/appointments/mine')->json('upcoming'));
    }

    public function test_a_booking_under_review_shows_no_place_in_line(): void
    {
        Appointment::factory()->for($this->patient, 'patient')->create([
            'provider_id' => $this->dentist->id,
            'scheduled_at' => $this->tuesday->copy()->setTime(9, 0),
            'status' => 'pending',
        ]);
        Sanctum::actingAs($this->patient);

        $this->getJson('/api/appointments/mine')
            ->assertOk()
            ->assertJsonPath('upcoming.0.status', 'pending')
            ->assertJsonPath('upcoming.0.people_ahead', null);
    }

    public function test_a_patient_can_cancel_a_booking_under_review(): void
    {
        $pending = Appointment::factory()->for($this->patient, 'patient')->create(['provider_id' => $this->dentist->id, 'status' => 'pending']);
        Sanctum::actingAs($this->patient);

        $this->postJson("/api/appointments/{$pending->id}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
    }

    public function test_once_checked_in_the_booking_is_left_to_the_queue(): void
    {
        $checkedIn = Appointment::factory()->for($this->patient, 'patient')->create(['status' => 'checked_in']);
        Sanctum::actingAs($this->patient);

        $this->postJson("/api/appointments/{$checkedIn->id}/cancel")->assertUnprocessable();
        $this->assertSame('checked_in', $checkedIn->fresh()->status);
    }

    public function test_the_dentist_sees_only_their_own_bookings(): void
    {
        $physician = User::factory()->physician()->create();
        $mine = Appointment::factory()->create(['provider_id' => $this->dentist->id, 'scheduled_at' => $this->tuesday->copy()->setTime(9, 0)]);
        Appointment::factory()->create(['provider_id' => $physician->id, 'scheduled_at' => $this->tuesday->copy()->setTime(9, 0)]);

        Sanctum::actingAs($this->dentist);

        // Asking for someone else's list still returns only theirs.
        $this->getJson("/api/appointments?date=2026-10-13&provider_id={$physician->id}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $mine->id)
            ->assertJsonPath('0.patient.school_id', $mine->patient->school_id);
    }

    public function test_no_show_is_only_for_ones_own_patients_and_only_after_their_time(): void
    {
        $physician = User::factory()->physician()->create();
        $mine = Appointment::factory()->create(['provider_id' => $this->dentist->id, 'scheduled_at' => $this->tuesday->copy()->setTime(9, 0)]);
        $theirs = Appointment::factory()->create(['provider_id' => $physician->id, 'scheduled_at' => $this->tuesday->copy()->setTime(9, 0)]);

        Sanctum::actingAs($this->dentist);

        $this->patchJson("/api/appointments/{$theirs->id}/no-show")->assertForbidden();
        $this->patchJson("/api/appointments/{$mine->id}/no-show")->assertUnprocessable();

        $this->travelTo($this->tuesday->copy()->setTime(9, 20));
        $this->patchJson("/api/appointments/{$mine->id}/no-show")->assertOk()->assertJsonPath('status', 'no_show');
    }
}
