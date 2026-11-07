<?php

namespace Tests\Feature\Analytics;

use App\Models\Appointment;
use App\Models\QueueEntry;
use App\Models\User;
use App\Support\Udd;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "How many BSIT students came today, and how many STE were skipped?"
 */
class DepartmentBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private User $nurse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-12 15:00:00'));
        $this->nurse = User::factory()->nurse()->create();
    }

    private function student(string $course): User
    {
        return User::factory()->patient()->create(['course' => $course, 'department' => Udd::departmentOfCourse($course)]);
    }

    private function queued(User $patient, string $status): void
    {
        QueueEntry::factory()->for($patient, 'patient')->create([
            'queue_date' => today(),
            'status' => $status,
            'handled_by_id' => $status === 'done' ? $this->nurse->id : null,
        ]);
    }

    public function test_counts_are_split_by_department_and_course(): void
    {
        $this->queued($this->student('BSIT'), 'done');
        $this->queued($this->student('BSIT'), 'done');
        $this->queued($this->student('BSCS'), 'skipped');
        $this->queued($this->student('BEEd'), 'skipped');

        // A dental patient seen at their booked time counts as a visit too.
        Appointment::factory()->create([
            'patient_id' => $this->student('BSTM')->id,
            'provider_id' => User::factory()->dentist()->create()->id,
            'scheduled_at' => today()->setTime(9, 0),
            'status' => 'completed',
        ]);

        Sanctum::actingAs($this->nurse);

        $response = $this->getJson('/api/analytics/breakdown')->assertOk();
        $departments = collect($response->json('departments'))->keyBy('code');

        $this->assertSame(3, $departments['SITE']['visited']);
        $this->assertSame(2, $departments['SITE']['served']);
        $this->assertSame(1, $departments['SITE']['skipped']);
        $this->assertSame(2, collect($departments['SITE']['courses'])->firstWhere('code', 'BSIT')['visited']);
        $this->assertSame(1, $departments['STE']['skipped']);
        $this->assertSame(1, $departments['SIHM']['visited']);
        // Departments nobody came from are still listed, at zero.
        $this->assertSame(0, $departments['SOE']['visited']);

        $this->assertSame(5, $response->json('totals.visited'));
        $this->assertSame(2, $response->json('totals.skipped'));
    }

    public function test_the_nurses_own_bookings_are_not_counted_twice(): void
    {
        $patient = $this->student('BSIT');
        $booking = Appointment::factory()->create([
            'patient_id' => $patient->id,
            'provider_id' => $this->nurse->id,
            'scheduled_at' => today()->setTime(10, 0),
            'status' => 'completed',
        ]);
        QueueEntry::factory()->for($patient, 'patient')->create([
            'queue_date' => today(), 'status' => 'done', 'source' => 'appointment', 'appointment_id' => $booking->id,
        ]);

        Sanctum::actingAs($this->nurse);

        $site = collect($this->getJson('/api/analytics/breakdown')->json('departments'))->firstWhere('code', 'SITE');
        $this->assertSame(1, $site['visited']);
        $this->assertSame(1, $site['bookings']);
    }

    public function test_filtering_to_one_department(): void
    {
        $this->queued($this->student('BSIT'), 'done');
        $this->queued($this->student('BEEd'), 'done');

        Sanctum::actingAs($this->nurse);

        $response = $this->getJson('/api/analytics/breakdown?department=STE&from=2026-10-12&to=2026-10-12')->assertOk();

        $this->assertSame(['STE'], collect($response->json('departments'))->pluck('code')->all());
        $this->assertSame(1, $response->json('totals.visited'));
    }

    public function test_each_professionals_workload_is_reported(): void
    {
        $dentist = User::factory()->dentist()->create();
        foreach (['completed', 'completed', 'no_show', 'pending'] as $index => $status) {
            Appointment::factory()->create([
                'provider_id' => $dentist->id,
                'scheduled_at' => today()->setTime(8 + $index, 0),
                'status' => $status,
            ]);
        }

        Sanctum::actingAs($this->nurse);

        $dentistRow = collect($this->getJson('/api/analytics/breakdown')->json('professionals'))->firstWhere('id', $dentist->id);

        $this->assertSame('Dentist', $dentistRow['job_title']);
        $this->assertSame(3, $dentistRow['bookings']);
        $this->assertSame(2, $dentistRow['completed']);
        $this->assertSame(1, $dentistRow['no_shows']);
        $this->assertSame(1, $dentistRow['pending']);
    }
}
