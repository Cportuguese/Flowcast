<?php

namespace Tests\Feature\Seeders;

use App\Models\AnalyticsSnapshot;
use App\Models\Appointment;
use App\Models\QueueEntry;
use App\Models\User;
use App\Models\VisitRecord;
use App\Support\HealthRecord;
use Database\Seeders\DemoHistorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * The synthetic history must follow the same rules as the live clinic, or
 * the model learns a clinic that does not exist. Three weeks are enough to
 * check them.
 */
class DemoHistorySeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-12 07:00:00'));

        $seeder = new DemoHistorySeeder;
        $seeder->days = 21;
        $this->app->instance(DemoHistorySeeder::class, $seeder);

        $this->seed(DemoHistorySeeder::class);
    }

    public function test_the_clinic_is_closed_on_sundays_and_bookings_follow_each_professionals_days(): void
    {
        $this->assertTrue(QueueEntry::exists());
        $this->assertFalse(QueueEntry::get()->contains(fn (QueueEntry $entry) => $entry->queue_date->isSunday()));

        $byProfession = Appointment::with('provider')->get()->groupBy(fn (Appointment $booking) => $booking->provider->profession);

        $this->assertEqualsCanonicalizing([2, 4], $byProfession['dentist']->map(fn ($b) => $b->scheduled_at->dayOfWeek)->unique()->values()->all());
        $this->assertSame([5], $byProfession['physician']->map(fn ($b) => $b->scheduled_at->dayOfWeek)->unique()->values()->all());
        $this->assertTrue($byProfession['physician']->every(fn ($b) => $b->scheduled_at->format('H:i') >= '13:00' && $b->scheduled_at->format('H:i') < '15:00'));
    }

    public function test_dentist_and_physician_patients_are_seen_without_a_queue_number(): void
    {
        $completed = Appointment::where('status', 'completed')
            ->whereIn('provider_id', User::whereIn('profession', ['dentist', 'physician'])->select('id'))
            ->get();

        $this->assertNotEmpty($completed);

        foreach ($completed as $booking) {
            $visit = VisitRecord::where('appointment_id', $booking->id)->sole();
            $this->assertSame($booking->provider_id, $visit->created_by_id);
            $this->assertNull($visit->queue_entry_id);
            $this->assertFalse(QueueEntry::where('appointment_id', $booking->id)->exists());
        }
    }

    /**
     * Whenever the nurse made a call while an on-time booked patient's time
     * had come, she called a booked patient — never a walk-in.
     */
    public function test_on_time_booked_patients_were_called_ahead_of_walk_ins(): void
    {
        $checked = 0;

        foreach (QueueEntry::with('appointment')->whereNotNull('called_at')->get()->groupBy(fn ($e) => $e->queue_date->toDateString()) as $day) {
            foreach ($day->where('source', 'appointment') as $booked) {
                $slot = $booked->appointment->scheduled_at;

                if ($booked->joined_at->gt($slot->copy()->addMinutes(15))) {
                    continue; // late: waits like a walk-in
                }

                $dueFrom = $booked->joined_at->max($slot);

                $walkInsCalledMeanwhile = $day->filter(fn (QueueEntry $other) => $other->source === 'walk_in'
                    && $other->called_at->gte($dueFrom)
                    && $other->called_at->lt($booked->called_at));

                $this->assertCount(0, $walkInsCalledMeanwhile, "Booked #{$booked->queue_number} was due at {$dueFrom} but walk-ins were called first.");
                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked);
    }

    public function test_bookings_end_the_way_their_queue_entry_did(): void
    {
        foreach (QueueEntry::with('appointment')->where('source', 'appointment')->get() as $entry) {
            $this->assertSame($entry->status === 'done' ? 'completed' : 'no_show', $entry->appointment->status);
        }
    }

    public function test_every_sample_patient_has_a_valid_health_record(): void
    {
        $patients = User::where('email', 'like', 'demo.patient%@flowcast.test')->get();

        $this->assertCount(240, $patients);

        foreach ($patients as $patient) {
            $this->assertTrue($patient->hasCompleteHealthRecord(), $patient->email);

            $input = [
                ...$patient->only(['first_name', 'middle_name', 'last_name', 'sex', 'home_address', 'patient_type', 'department', 'course', 'contact_no']),
                'birthdate' => $patient->birthdate->toDateString(),
                'school_id' => $patient->school_id,
            ];
            $validator = Validator::make($input, HealthRecord::rules($input, $patient));

            $this->assertTrue($validator->passes(), $patient->email.': '.implode(' ', $validator->errors()->all()));
        }
    }

    /**
     * The history is written with the same midnight time Eloquent uses for
     * queue_date, so the snapshot command finds it on SQLite as well as
     * MySQL (it used to find nothing on SQLite).
     */
    public function test_every_clinic_day_is_snapshotted_for_the_forecasts(): void
    {
        $clinicDays = QueueEntry::get()->map(fn (QueueEntry $entry) => $entry->queue_date->toDateString())->unique();

        $this->assertSame($clinicDays->count(), AnalyticsSnapshot::distinct()->count('date'));
    }
}
