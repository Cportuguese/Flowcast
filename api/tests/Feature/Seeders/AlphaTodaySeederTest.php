<?php

namespace Tests\Feature\Seeders;

use App\Models\Appointment;
use App\Models\QueueEntry;
use App\Models\User;
use App\Models\VisitRecord;
use Database\Seeders\AlphaTodaySeeder;
use Database\Seeders\DevUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The "today" seeder must leave a live demo ready to run: a queue already
 * moving, nothing faked into the predicted-vs-actual chart, a booking the
 * demo patient can check in for from the phone, the dentist's or the
 * physician's day if it is theirs, and bookings for the nurse to review.
 */
class AlphaTodaySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_adds_a_morning_queue_and_books_the_demo_patient_late_today(): void
    {
        $this->clinicOn('2026-10-06 10:30:00'); // a Tuesday

        $this->seed(AlphaTodaySeeder::class);

        $this->assertSame(
            ['done', 'done', 'done', 'skipped', 'in_consultation', 'waiting', 'waiting', 'waiting'],
            QueueEntry::whereDate('queue_date', today())->orderBy('queue_number')->pluck('status')->all(),
        );
        $this->assertSame(0, QueueEntry::whereNotNull('predicted_wait_minutes')->count());

        $demoPatient = User::where('email', DevUsersSeeder::PATIENT_EMAIL)->sole();
        $booking = Appointment::with('provider')->where('patient_id', $demoPatient->id)->sole();
        $this->assertSame('booked', $booking->status);
        $this->assertSame('nurse', $booking->provider->profession);
        // Nurse Portia's last 20-minute slot before 6 PM.
        $this->assertSame('2026-10-06 17:40:00', $booking->scheduled_at->toDateTimeString());
        $this->assertFalse(QueueEntry::where('patient_id', $demoPatient->id)->exists());
    }

    public function test_on_a_dentist_day_the_dentists_earlier_patients_are_already_seen(): void
    {
        $this->clinicOn('2026-10-06 10:30:00'); // Tuesday: the dentist is in

        $this->seed(AlphaTodaySeeder::class);

        $dentist = User::providers()->where('profession', 'dentist')->sole();
        $today = Appointment::where('provider_id', $dentist->id)->whereDate('scheduled_at', today())->get();

        $this->assertCount(5, $today);
        $this->assertTrue($today->where('status', 'completed')->isNotEmpty());
        $this->assertTrue($today->where('status', 'booked')->isNotEmpty());
        $this->assertSame(
            $today->where('status', 'completed')->count(),
            VisitRecord::whereIn('appointment_id', $today->pluck('id'))->where('created_by_id', $dentist->id)->count(),
        );
    }

    public function test_keeps_a_few_bookings_waiting_for_review(): void
    {
        $this->clinicOn('2026-10-06 10:30:00');

        $this->seed(AlphaTodaySeeder::class);

        $this->assertGreaterThanOrEqual(3, Appointment::where('status', 'pending')->where('scheduled_at', '>', now())->count());
    }

    public function test_running_it_again_the_same_day_adds_nothing(): void
    {
        $this->clinicOn('2026-10-06 10:30:00');
        $this->seed(AlphaTodaySeeder::class);
        $appointments = Appointment::count();

        $this->seed(AlphaTodaySeeder::class);

        $this->assertSame(8, QueueEntry::count());
        $this->assertSame($appointments, Appointment::count());
    }

    public function test_keeps_sample_times_inside_opening_hours_when_seeded_before_the_clinic_opens(): void
    {
        $this->clinicOn('2026-10-06 07:20:00');

        $this->seed(AlphaTodaySeeder::class);

        $first = QueueEntry::orderBy('joined_at')->first();
        $this->assertSame('2026-10-06 08:02:00', $first->joined_at->toDateTimeString());
    }

    public function test_adds_nothing_on_a_day_the_clinic_is_closed(): void
    {
        $this->clinicOn('2026-10-04 10:00:00'); // a Sunday

        $this->seed(AlphaTodaySeeder::class);

        $this->assertSame(0, QueueEntry::count());
        $this->assertSame(0, Appointment::count());
    }

    /**
     * The dev logins with UDD's hours (Mon-Sat 8-6; dentist Tue/Thu;
     * physician Friday afternoon) and enough patients to fill the morning.
     */
    private function clinicOn(string $now): void
    {
        $this->travelTo(Carbon::parse($now));
        $this->seed(DevUsersSeeder::class);

        User::factory()->patient()->count(12)->create();
    }
}
