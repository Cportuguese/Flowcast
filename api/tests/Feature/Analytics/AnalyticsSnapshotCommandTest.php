<?php

namespace Tests\Feature\Analytics;

use App\Models\AnalyticsSnapshot;
use App\Models\EventDay;
use App\Models\QueueEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AnalyticsSnapshotCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_buckets_entries_by_hour_and_flags_the_busiest_one(): void
    {
        $date = Carbon::parse('2026-08-10');

        $this->makeEntry($date, 8, waitMinutes: 5, consultMinutes: 12);
        $this->makeEntry($date, 8, waitMinutes: 10, consultMinutes: 20);
        $this->makeEntry($date, 9, waitMinutes: 3, consultMinutes: 8);
        $this->makeEntry($date, 9);
        $this->makeEntry($date, 9);

        Artisan::call('analytics:snapshot', ['date' => $date->toDateString()]);

        $this->assertSame(2, AnalyticsSnapshot::where('date', $date->toDateString())->count());

        $hour8 = AnalyticsSnapshot::where('date', $date->toDateString())->where('hour', 8)->firstOrFail();
        $this->assertSame(2, $hour8->arrival_count);
        $this->assertSame(8, $hour8->avg_wait_time);
        $this->assertSame(16, $hour8->avg_consult_time);
        $this->assertFalse($hour8->peak_hour);
        $this->assertFalse($hour8->event_day);

        $hour9 = AnalyticsSnapshot::where('date', $date->toDateString())->where('hour', 9)->firstOrFail();
        $this->assertSame(3, $hour9->arrival_count);
        $this->assertSame(3, $hour9->avg_wait_time);
        $this->assertSame(8, $hour9->avg_consult_time);
        $this->assertTrue($hour9->peak_hour);
    }

    public function test_it_flags_snapshots_for_a_flagged_event_day(): void
    {
        $date = Carbon::parse('2026-08-11');
        EventDay::create(['date' => $date, 'label' => 'PATHFIT day']);

        $this->makeEntry($date, 10);

        Artisan::call('analytics:snapshot', ['date' => $date->toDateString()]);

        $this->assertTrue(AnalyticsSnapshot::where('date', $date->toDateString())->firstOrFail()->event_day);
    }

    public function test_rerunning_the_command_is_idempotent(): void
    {
        $date = Carbon::parse('2026-08-12');
        $this->makeEntry($date, 8);
        $this->makeEntry($date, 9);

        Artisan::call('analytics:snapshot', ['date' => $date->toDateString()]);
        Artisan::call('analytics:snapshot', ['date' => $date->toDateString()]);

        $this->assertSame(2, AnalyticsSnapshot::where('date', $date->toDateString())->count());
    }

    public function test_defaults_to_yesterday_when_no_date_given(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));

        $this->makeEntry(Carbon::parse('2026-08-14'), 9);

        Artisan::call('analytics:snapshot');

        Carbon::setTestNow();

        $this->assertSame(1, AnalyticsSnapshot::where('date', '2026-08-14')->count());
    }

    private function makeEntry(Carbon $date, int $hour, ?int $waitMinutes = null, ?int $consultMinutes = null): QueueEntry
    {
        $joinedAt = $date->copy()->setTime($hour, 0);
        $calledAt = $waitMinutes !== null ? $joinedAt->copy()->addMinutes($waitMinutes) : null;
        $completedAt = ($calledAt && $consultMinutes !== null) ? $calledAt->copy()->addMinutes($consultMinutes) : null;

        return QueueEntry::factory()->create([
            'queue_date' => $date->toDateString(),
            'joined_at' => $joinedAt,
            'called_at' => $calledAt,
            'completed_at' => $completedAt,
            'status' => $completedAt ? 'done' : ($calledAt ? 'in_consultation' : 'waiting'),
        ]);
    }
}
