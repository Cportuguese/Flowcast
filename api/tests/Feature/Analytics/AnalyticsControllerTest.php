<?php

namespace Tests\Feature\Analytics;

use App\Models\AnalyticsSnapshot;
use App\Models\EventDay;
use App\Models\QueueEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnalyticsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dashboard_returns_todays_stats_and_event_day_flag(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 09:00:00'));

        EventDay::create(['date' => today(), 'label' => 'Enrollment week']);

        QueueEntry::factory()->create([
            'queue_date' => today(),
            'joined_at' => today()->copy()->setTime(8, 0),
            'called_at' => today()->copy()->setTime(8, 5),
            'completed_at' => today()->copy()->setTime(8, 15),
            'status' => 'done',
        ]);
        QueueEntry::factory()->create([
            'queue_date' => today(),
            'joined_at' => today()->copy()->setTime(8, 30),
            'status' => 'waiting',
        ]);

        Sanctum::actingAs(User::factory()->personnel()->create());

        $this->getJson('/api/analytics/dashboard')
            ->assertOk()
            ->assertJsonPath('is_event_day', true)
            ->assertJsonPath('event_label', 'Enrollment week')
            ->assertJsonPath('stats.arrivals_today', 2)
            ->assertJsonPath('stats.avg_wait_minutes_today', 5)
            ->assertJsonPath('stats.avg_consult_minutes_today', 10)
            ->assertJsonPath('stats.currently_waiting', 1)
            ->assertJsonPath('stats.completed_today', 1);
    }

    public function test_volume_forecast_uses_recency_weighted_same_weekday_average(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 08:00:00'));
        $target = today()->copy()->addDay();

        AnalyticsSnapshot::factory()->create(['date' => $target->copy()->subWeek()->toDateString(), 'hour' => 9, 'arrival_count' => 30]);
        AnalyticsSnapshot::factory()->create(['date' => $target->copy()->subWeeks(2)->toDateString(), 'hour' => 9, 'arrival_count' => 20]);
        AnalyticsSnapshot::factory()->create(['date' => $target->copy()->subWeeks(3)->toDateString(), 'hour' => 9, 'arrival_count' => 10]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->getJson('/api/analytics/volume-forecast?days=1')->assertOk();
        $forecastRow = collect($response->json('series'))->firstWhere('date', $target->toDateString());

        $this->assertNotNull($forecastRow);
        $this->assertSame('forecast', $forecastRow['type']);
        $this->assertSame(24, $forecastRow['predicted_arrivals']);
        $this->assertFalse($forecastRow['is_event_day']);
    }

    public function test_volume_forecast_multiplies_by_event_day_factor(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 08:00:00'));
        $target = today()->copy()->addDay();

        AnalyticsSnapshot::factory()->create(['date' => $target->copy()->subWeek()->toDateString(), 'hour' => 9, 'arrival_count' => 30]);
        AnalyticsSnapshot::factory()->create(['date' => $target->copy()->subWeeks(2)->toDateString(), 'hour' => 9, 'arrival_count' => 20]);
        AnalyticsSnapshot::factory()->create(['date' => $target->copy()->subWeeks(3)->toDateString(), 'hour' => 9, 'arrival_count' => 10]);

        EventDay::create(['date' => $target, 'label' => 'Enrollment']);

        Sanctum::actingAs(User::factory()->personnel()->create());

        $response = $this->getJson('/api/analytics/volume-forecast?days=1')->assertOk();
        $forecastRow = collect($response->json('series'))->firstWhere('date', $target->toDateString());

        $this->assertSame(35, $forecastRow['predicted_arrivals']);
        $this->assertTrue($forecastRow['is_event_day']);
    }

    public function test_peak_hours_reads_stored_snapshot_for_a_past_date(): void
    {
        $date = Carbon::parse('2026-07-01');
        AnalyticsSnapshot::factory()->create(['date' => $date->toDateString(), 'hour' => 8, 'arrival_count' => 4, 'peak_hour' => false]);
        AnalyticsSnapshot::factory()->create(['date' => $date->toDateString(), 'hour' => 9, 'arrival_count' => 9, 'peak_hour' => true]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson("/api/analytics/peak-hours?date={$date->toDateString()}")
            ->assertOk()
            ->assertJsonPath('is_forecast', false)
            ->assertJsonPath('peak_hour', 9)
            ->assertJsonCount(2, 'hours');
    }

    public function test_peak_hours_for_today_is_computed_live_from_the_queue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 14:00:00'));

        QueueEntry::factory()->create(['queue_date' => today(), 'joined_at' => today()->copy()->setTime(9, 0)]);
        QueueEntry::factory()->create(['queue_date' => today(), 'joined_at' => today()->copy()->setTime(9, 15)]);
        QueueEntry::factory()->create(['queue_date' => today(), 'joined_at' => today()->copy()->setTime(10, 0)]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/analytics/peak-hours')
            ->assertOk()
            ->assertJsonPath('is_today', true)
            ->assertJsonPath('peak_hour', 9);
    }

    public function test_wait_time_forecast_returns_trend_and_hour_specific_forecast(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 08:00:00'));

        AnalyticsSnapshot::factory()->create(['date' => today()->copy()->subDays(2)->toDateString(), 'hour' => 9, 'arrival_count' => 4, 'avg_wait_time' => 10]);
        AnalyticsSnapshot::factory()->create(['date' => today()->copy()->subDays(2)->toDateString(), 'hour' => 10, 'arrival_count' => 6, 'avg_wait_time' => 20]);
        AnalyticsSnapshot::factory()->create(['date' => today()->copy()->subDays(9)->toDateString(), 'hour' => 9, 'arrival_count' => 5, 'avg_wait_time' => 6]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->getJson('/api/analytics/wait-time-forecast?hour=9')->assertOk();

        $trendRow = collect($response->json('trend'))->firstWhere('date', today()->copy()->subDays(2)->toDateString());
        $this->assertSame(16, $trendRow['avg_wait_minutes']);
        $this->assertSame(9, $response->json('forecast_for_hour'));
    }
}
