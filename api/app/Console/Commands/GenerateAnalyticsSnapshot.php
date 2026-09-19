<?php

namespace App\Console\Commands;

use App\Models\AnalyticsSnapshot;
use App\Models\EventDay;
use App\Models\QueueEntry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('analytics:snapshot {date? : Y-m-d date to snapshot; defaults to yesterday}')]
#[Description("Aggregate a day's queue activity into hourly analytics_snapshots rows.")]
class GenerateAnalyticsSnapshot extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $date = $this->argument('date')
            ? Carbon::createFromFormat('Y-m-d', $this->argument('date'))->startOfDay()
            : Carbon::yesterday();

        $entries = QueueEntry::where('queue_date', $date)->get();
        $isEventDay = EventDay::whereDate('date', $date)->exists();

        $byHour = $entries->groupBy(fn (QueueEntry $entry) => $entry->joined_at->hour);
        $rows = [];

        foreach ($byHour as $hour => $hourEntries) {
            $called = $hourEntries->filter(fn (QueueEntry $entry) => $entry->called_at);
            $completed = $hourEntries->filter(fn (QueueEntry $entry) => $entry->called_at && $entry->completed_at);

            $rows[$hour] = [
                'date' => $date->toDateString(),
                'hour' => $hour,
                'arrival_count' => $hourEntries->count(),
                'avg_wait_time' => $called->isEmpty()
                    ? null
                    : (int) round($called->avg(fn (QueueEntry $entry) => $entry->joined_at->diffInMinutes($entry->called_at))),
                'avg_consult_time' => $completed->isEmpty()
                    ? null
                    : (int) round($completed->avg(fn (QueueEntry $entry) => $entry->called_at->diffInMinutes($entry->completed_at))),
                'event_day' => $isEventDay,
                'peak_hour' => false,
            ];
        }

        if (! empty($rows)) {
            $peakHour = collect($rows)->sortByDesc('arrival_count')->keys()->first();
            $rows[$peakHour]['peak_hour'] = true;
        }

        foreach ($rows as $row) {
            AnalyticsSnapshot::updateOrCreate(
                ['date' => $row['date'], 'hour' => $row['hour']],
                $row,
            );
        }

        $this->info("Snapshotted {$entries->count()} queue entries across ".count($rows)." hour(s) for {$date->toDateString()}.");

        return self::SUCCESS;
    }
}
