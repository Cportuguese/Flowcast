<?php

namespace Tests\Feature\Analytics;

use App\Models\AnalyticsSnapshot;
use App\Models\Appointment;
use App\Models\QueueEntry;
use App\Models\User;
use App\Models\VisitRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_summary_totals_by_day_and_overall(): void
    {
        QueueEntry::factory()->create(['queue_date' => '2026-05-01', 'source' => 'walk_in', 'status' => 'done']);
        QueueEntry::factory()->create(['queue_date' => '2026-05-01', 'source' => 'appointment', 'status' => 'skipped']);
        QueueEntry::factory()->create(['queue_date' => '2026-05-02', 'source' => 'walk_in', 'status' => 'done']);

        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->getJson('/api/admin/reports/queue-summary?date_from=2026-05-01&date_to=2026-05-02')->assertOk();

        $days = collect($response->json('days'))->keyBy('date');
        $this->assertSame(2, $days['2026-05-01']['total_entries']);
        $this->assertSame(1, $days['2026-05-01']['walk_in']);
        $this->assertSame(1, $days['2026-05-01']['appointment']);
        $this->assertSame(1, $days['2026-05-02']['total_entries']);
        $this->assertSame(3, $response->json('totals.total_entries'));
        $this->assertSame(2, $response->json('totals.done'));
        $this->assertSame(1, $response->json('totals.skipped'));
    }

    public function test_visit_summary_counts_and_top_complaints(): void
    {
        $this->visitAt(Carbon::parse('2026-05-01 09:00'), ['complaint' => 'Cough and colds']);
        $this->visitAt(Carbon::parse('2026-05-01 10:00'), ['complaint' => 'Cough and colds']);
        $this->visitAt(Carbon::parse('2026-05-02 09:00'), ['complaint' => 'Sprain', 'referred_to' => 'Orthopedics']);

        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->getJson('/api/admin/reports/visit-summary?date_from=2026-05-01&date_to=2026-05-02')->assertOk();

        $this->assertSame(3, $response->json('total_visits'));
        $this->assertSame(1, $response->json('referred_count'));
        $this->assertSame('Cough and colds', $response->json('top_complaints.0.complaint'));
        $this->assertSame(2, $response->json('top_complaints.0.count'));
    }

    public function test_no_show_summary_counts_and_rate(): void
    {
        Appointment::factory()->create(['scheduled_at' => Carbon::parse('2026-06-05 09:00'), 'status' => 'no_show']);
        Appointment::factory()->create(['scheduled_at' => Carbon::parse('2026-06-06 09:00'), 'status' => 'completed']);
        Appointment::factory()->create(['scheduled_at' => Carbon::parse('2026-06-07 09:00'), 'status' => 'completed']);

        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->getJson('/api/admin/reports/no-show-summary?month=2026-06')->assertOk();

        $this->assertSame(1, $response->json('total_no_shows'));
        $this->assertSame(3, $response->json('total_appointments'));
        $this->assertEqualsWithDelta(1 / 3, $response->json('no_show_rate'), 0.001);
    }

    public function test_peak_hours_by_weekday_averages_across_matching_dates(): void
    {
        AnalyticsSnapshot::factory()->create(['date' => '2026-08-03', 'hour' => 9, 'arrival_count' => 4]);
        AnalyticsSnapshot::factory()->create(['date' => '2026-08-10', 'hour' => 9, 'arrival_count' => 6]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->getJson('/api/admin/reports/peak-hours-by-weekday')->assertOk();

        $monday = collect($response->json('weekdays'))->firstWhere('day_of_week', Carbon::parse('2026-08-03')->dayOfWeek);
        $hour9 = collect($monday['hours'])->firstWhere('hour', 9);

        $this->assertEqualsWithDelta(5.0, $hour9['avg_arrivals'], 0.001);
        $this->assertSame(9, $monday['peak_hour']);
    }

    private function visitAt(Carbon $date, array $attributes = []): VisitRecord
    {
        $visit = VisitRecord::factory()->create($attributes);
        $visit->forceFill(['created_at' => $date])->save();

        return $visit->fresh();
    }
}
