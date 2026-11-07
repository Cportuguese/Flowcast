<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsSnapshot;
use App\Models\Appointment;
use App\Models\QueueEntry;
use App\Models\VisitRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ReportController extends Controller
{
    private const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    public function queueSummary(Request $request)
    {
        $validated = $request->validate([
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        // Plain rows, not models: a range of years is tens of thousands of
        // entries, and hydrating each one (and its dates) took seconds.
        $entries = QueueEntry::whereBetween('queue_date', [
            Carbon::createFromFormat('Y-m-d', $validated['date_from'])->startOfDay(),
            Carbon::createFromFormat('Y-m-d', $validated['date_to'])->startOfDay(),
        ])->toBase()->get(['queue_date', 'source', 'status', 'joined_at', 'called_at', 'completed_at']);

        $days = $entries->groupBy(fn (object $entry) => substr($entry->queue_date, 0, 10))
            ->map(fn ($dayEntries, $date) => $this->summarizeQueueDay($date, $dayEntries))
            ->sortBy('date')
            ->values();

        return response()->json([
            'date_from' => $validated['date_from'],
            'date_to' => $validated['date_to'],
            'days' => $days,
            'totals' => $this->summarizeQueueDay(null, $entries),
        ]);
    }

    public function visitSummary(Request $request)
    {
        $validated = $request->validate([
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        $from = Carbon::createFromFormat('Y-m-d', $validated['date_from'])->startOfDay();
        $to = Carbon::createFromFormat('Y-m-d', $validated['date_to'])->endOfDay();

        $visits = VisitRecord::whereBetween('created_at', [$from, $to])->get();

        $byDay = $visits->groupBy(fn (VisitRecord $visit) => $visit->created_at->toDateString())
            ->map(fn ($dayVisits, $date) => ['date' => $date, 'count' => $dayVisits->count()])
            ->sortBy('date')
            ->values();

        // Complaints, not interventions: an intervention is a free-text
        // account of what was done, while complaints repeat ("Headache",
        // "Fever") and are what the clinic plans its stock and staffing by.
        $topComplaints = $visits->groupBy('complaint')
            ->map(fn ($group, $complaint) => ['complaint' => $complaint, 'count' => $group->count()])
            ->sortByDesc('count')
            ->take(10)
            ->values();

        return response()->json([
            'date_from' => $validated['date_from'],
            'date_to' => $validated['date_to'],
            'total_visits' => $visits->count(),
            'referred_count' => $visits->whereNotNull('referred_to')->count(),
            'by_day' => $byDay,
            'top_complaints' => $topComplaints,
        ]);
    }

    public function noShowSummary(Request $request)
    {
        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $month = isset($validated['month'])
            ? Carbon::createFromFormat('Y-m', $validated['month'])->startOfMonth()
            : Carbon::now()->startOfMonth();

        $appointments = Appointment::with('patient:id,name')
            ->whereBetween('scheduled_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->get();

        $byDay = $appointments->groupBy(fn (Appointment $appointment) => $appointment->scheduled_at->toDateString())
            ->map(fn ($dayAppointments, $date) => [
                'date' => $date,
                'no_show_count' => $dayAppointments->where('status', 'no_show')->count(),
                'appointment_count' => $dayAppointments->count(),
            ])
            ->sortBy('date')
            ->values();

        $noShows = $appointments->where('status', 'no_show')->values();

        return response()->json([
            'month' => $month->format('Y-m'),
            'total_no_shows' => $noShows->count(),
            'total_appointments' => $appointments->count(),
            'no_show_rate' => $appointments->isEmpty() ? 0 : round($noShows->count() / $appointments->count(), 3),
            'by_day' => $byDay,
            'appointments' => $noShows->map(fn (Appointment $appointment) => [
                'id' => $appointment->id,
                'patient' => ['id' => $appointment->patient->id, 'name' => $appointment->patient->name],
                'scheduled_at' => $appointment->scheduled_at->toIso8601String(),
                'reason' => $appointment->reason,
            ])->values(),
        ]);
    }

    public function peakHoursByWeekday()
    {
        $since = Carbon::today()->subWeeks(26);

        $rows = AnalyticsSnapshot::where('date', '>=', $since->toDateString())->get();

        $byWeekday = $rows->groupBy(fn (AnalyticsSnapshot $row) => Carbon::parse($row->date)->dayOfWeek);

        $weekdays = collect(range(0, 6))->map(function (int $dayOfWeek) use ($byWeekday) {
            $rowsForDay = $byWeekday->get($dayOfWeek, collect());
            $numDates = $rowsForDay->pluck('date')->unique()->count();

            $hours = $rowsForDay->groupBy('hour')
                ->map(fn ($hourRows, $hour) => [
                    'hour' => (int) $hour,
                    'avg_arrivals' => $numDates > 0 ? round($hourRows->sum('arrival_count') / $numDates, 1) : 0,
                ])
                ->sortBy('hour')
                ->values();

            return [
                'day_of_week' => $dayOfWeek,
                'label' => self::DAY_NAMES[$dayOfWeek],
                'hours' => $hours,
                'peak_hour' => $hours->isEmpty() ? null : $hours->sortByDesc('avg_arrivals')->first()['hour'],
            ];
        });

        return response()->json(['weekdays' => $weekdays]);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  Collection<int, object>  $entries  rows with the queue columns, as plain objects
     */
    private function summarizeQueueDay(?string $date, Collection $entries): array
    {
        $completed = $entries->filter(fn (object $entry) => $entry->called_at && $entry->completed_at);
        $called = $entries->filter(fn (object $entry) => $entry->called_at);

        $minutesBetween = fn (string $from, string $to): float => (strtotime($to) - strtotime($from)) / 60;

        return [
            'date' => $date,
            'total_entries' => $entries->count(),
            'walk_in' => $entries->where('source', 'walk_in')->count(),
            'appointment' => $entries->where('source', 'appointment')->count(),
            'done' => $entries->where('status', 'done')->count(),
            'skipped' => $entries->where('status', 'skipped')->count(),
            'avg_wait_minutes' => $called->isEmpty()
                ? null
                : (int) round($called->avg(fn (object $entry) => $minutesBetween($entry->joined_at, $entry->called_at))),
            'avg_consult_minutes' => $completed->isEmpty()
                ? null
                : (int) round($completed->avg(fn (object $entry) => $minutesBetween($entry->called_at, $entry->completed_at))),
        ];
    }
}
