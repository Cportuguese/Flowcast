<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsSnapshot;
use App\Models\ClinicSchedule;
use App\Models\EventDay;
use App\Models\QueueEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AnalyticsController extends Controller
{
    /** Multiplier applied to a forecast when the target date is a flagged event day. */
    private const EVENT_DAY_FACTOR = 1.5;

    /** How many same-weekday occurrences feed a forecast, most recent weighted highest. */
    private const LOOKBACK_WEEKS = 8;

    /** Hard cap on how far back history queries scan. */
    private const LOOKBACK_CAP_WEEKS = 26;

    /** Trailing window shown on the volume/wait-time trend charts. */
    private const TREND_DAYS = 28;

    public function dashboard()
    {
        $today = Carbon::today();
        $entries = QueueEntry::where('queue_date', $today)->get();

        $called = $entries->filter(fn (QueueEntry $entry) => $entry->called_at);
        $completedWithTimes = $entries->filter(fn (QueueEntry $entry) => $entry->called_at && $entry->completed_at);

        $eventDay = EventDay::whereDate('date', $today)->first();

        return response()->json([
            'date' => $today->toDateString(),
            'is_event_day' => (bool) $eventDay,
            'event_label' => $eventDay?->label,
            'stats' => [
                'arrivals_today' => $entries->count(),
                'avg_wait_minutes_today' => $called->isEmpty()
                    ? null
                    : (int) round($called->avg(fn (QueueEntry $entry) => $entry->joined_at->diffInMinutes($entry->called_at))),
                'avg_consult_minutes_today' => $completedWithTimes->isEmpty()
                    ? null
                    : (int) round($completedWithTimes->avg(fn (QueueEntry $entry) => $entry->called_at->diffInMinutes($entry->completed_at))),
                'currently_waiting' => $entries->where('status', 'waiting')->count(),
                'completed_today' => $entries->where('status', 'done')->count(),
            ],
            'upcoming_hours' => $this->upcomingHours($today, $entries),
        ]);
    }

    public function volumeForecast(Request $request)
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'between:1,30'],
        ]);
        $days = $validated['days'] ?? 7;

        $since = Carbon::today()->subDays(self::TREND_DAYS);

        $actualTotals = AnalyticsSnapshot::where('date', '>=', $since->toDateString())
            ->selectRaw('date, SUM(arrival_count) as total')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $eventDates = EventDay::where('date', '>=', $since)->get()
            ->pluck('date')->map(fn (Carbon $d) => $d->toDateString())->all();

        $series = $actualTotals->map(fn ($row) => [
            'date' => $row->date,
            'type' => 'actual',
            'arrivals' => (int) $row->total,
            'is_event_day' => in_array($row->date, $eventDates, true),
        ])->values()->all();

        for ($i = 1; $i <= $days; $i++) {
            $date = Carbon::today()->addDays($i);
            $forecast = $this->predictedArrivalsForDate($date);

            $series[] = [
                'date' => $date->toDateString(),
                'type' => 'forecast',
                'predicted_arrivals' => $forecast['predicted_arrivals'],
                'is_event_day' => $forecast['is_event_day'],
            ];
        }

        return response()->json(['days' => $days, 'series' => $series]);
    }

    public function waitTimeForecast(Request $request)
    {
        $validated = $request->validate([
            'hour' => ['nullable', 'integer', 'between:0,23'],
        ]);

        $since = Carbon::today()->subDays(self::TREND_DAYS);
        $rows = AnalyticsSnapshot::where('date', '>=', $since->toDateString())->get();

        $trend = $rows->groupBy(fn (AnalyticsSnapshot $row) => $row->date)
            ->map(function (Collection $dateRows, string $date) {
                $totalArrivals = $dateRows->sum('arrival_count');
                $weighted = $dateRows->sum(fn (AnalyticsSnapshot $row) => ($row->avg_wait_time ?? 0) * $row->arrival_count);

                return [
                    'date' => $date,
                    'avg_wait_minutes' => $totalArrivals > 0 ? (int) round($weighted / $totalArrivals) : null,
                ];
            })
            ->filter(fn (array $row) => $row['avg_wait_minutes'] !== null)
            ->sortBy('date')
            ->values()
            ->all();

        $hour = $validated['hour'] ?? null;
        $forecastForHour = $hour === null ? null : $this->forecastWaitForHour($rows, $hour);

        return response()->json([
            'trend' => $trend,
            'hour' => $hour,
            'forecast_for_hour' => $forecastForHour,
        ]);
    }

    public function peakHours(Request $request)
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $date = isset($validated['date'])
            ? Carbon::createFromFormat('Y-m-d', $validated['date'])->startOfDay()
            : Carbon::today();

        $isEventDay = EventDay::whereDate('date', $date)->exists();

        if ($date->isToday()) {
            $hours = QueueEntry::where('queue_date', $date)->get()
                ->groupBy(fn (QueueEntry $entry) => $entry->joined_at->hour)
                ->map(fn (Collection $hourEntries, $hour) => ['hour' => (int) $hour, 'arrival_count' => $hourEntries->count()])
                ->sortBy('hour')
                ->values()
                ->all();

            return response()->json([
                'date' => $date->toDateString(),
                'is_today' => true,
                'is_forecast' => false,
                'is_event_day' => $isEventDay,
                'hours' => $hours,
                'peak_hour' => $this->busiestHour($hours),
            ]);
        }

        if ($date->isPast()) {
            $rows = AnalyticsSnapshot::where('date', $date->toDateString())->orderBy('hour')->get();
            $hours = $rows->map(fn (AnalyticsSnapshot $row) => ['hour' => $row->hour, 'arrival_count' => $row->arrival_count])->values()->all();
            $peakRow = $rows->firstWhere('peak_hour', true) ?? $rows->sortByDesc('arrival_count')->first();

            return response()->json([
                'date' => $date->toDateString(),
                'is_today' => false,
                'is_forecast' => false,
                'is_event_day' => $isEventDay,
                'hours' => $hours,
                'peak_hour' => $peakRow?->hour,
            ]);
        }

        $forecast = $this->predictedArrivalsForDate($date);
        $shares = $this->hourlySharesForWeekday($date->dayOfWeek);

        $hours = collect($shares)
            ->map(fn (float $share, $hour) => ['hour' => (int) $hour, 'arrival_count' => (int) round($forecast['predicted_arrivals'] * $share)])
            ->sortBy('hour')
            ->values()
            ->all();

        return response()->json([
            'date' => $date->toDateString(),
            'is_today' => false,
            'is_forecast' => true,
            'is_event_day' => $forecast['is_event_day'],
            'hours' => $hours,
            'peak_hour' => $this->busiestHour($hours),
        ]);
    }

    /**
     * @return array<int, array{hour: int, label: string, predicted_arrivals: int, estimated_wait_minutes: int}>
     */
    private function upcomingHours(Carbon $today, Collection $todayEntries): array
    {
        $schedule = ClinicSchedule::where('day_of_week', $today->dayOfWeek)->where('is_active', true)->first();

        if (! $schedule) {
            return [];
        }

        $endHour = (int) Carbon::parse($schedule->end_time)->format('H');
        $predictedTotal = $this->predictedArrivalsForDate($today)['predicted_arrivals'];
        $shares = $this->hourlySharesForWeekday($today->dayOfWeek);
        $avgConsult = $this->fallbackAvgConsultMinutes($todayEntries);

        $upcoming = [];

        for ($hour = Carbon::now()->hour + 1; $hour < $endHour; $hour++) {
            $predictedArrivals = (int) round($predictedTotal * ($shares[$hour] ?? 0));

            $upcoming[] = [
                'hour' => $hour,
                'label' => Carbon::createFromTime($hour)->format('g A'),
                'predicted_arrivals' => $predictedArrivals,
                'estimated_wait_minutes' => (int) round($predictedArrivals * $avgConsult),
            ];
        }

        return $upcoming;
    }

    /**
     * Today's completed-entry average consult time, falling back to the
     * trailing trend window, then a flat default before any data exists.
     */
    private function fallbackAvgConsultMinutes(Collection $todayEntries): float
    {
        $completedToday = $todayEntries->filter(fn (QueueEntry $entry) => $entry->called_at && $entry->completed_at);

        if ($completedToday->isNotEmpty()) {
            return max($completedToday->avg(fn (QueueEntry $entry) => $entry->called_at->diffInMinutes($entry->completed_at)), 1);
        }

        $trailing = AnalyticsSnapshot::where('date', '>=', Carbon::today()->subDays(self::TREND_DAYS)->toDateString())
            ->whereNotNull('avg_consult_time')
            ->avg('avg_consult_time');

        return $trailing ? max($trailing, 1) : 5;
    }

    /**
     * Recency-weighted average of a specific hour's avg_wait_time across the
     * trend window, multiplied by the event-day factor when today is flagged.
     */
    private function forecastWaitForHour(Collection $rows, int $hour): ?int
    {
        $matching = $rows->filter(fn (AnalyticsSnapshot $row) => $row->hour === $hour && $row->avg_wait_time !== null)
            ->sortByDesc(fn (AnalyticsSnapshot $row) => $row->date)
            ->values();

        if ($matching->isEmpty()) {
            return null;
        }

        $weightSum = 0;
        $weighted = 0;

        foreach ($matching as $i => $row) {
            $weight = 1 / ($i + 1);
            $weighted += $row->avg_wait_time * $weight;
            $weightSum += $weight;
        }

        $base = $weighted / $weightSum;
        $isEventDay = EventDay::whereDate('date', Carbon::today())->exists();

        return (int) round($isEventDay ? $base * self::EVENT_DAY_FACTOR : $base);
    }

    /**
     * Weighted-average forecast for a single date: recent same-weekday totals
     * (most recent weighted highest), falling back to any recent day when too
     * little weekday-specific history exists, times the event-day factor.
     *
     * @return array{predicted_arrivals: int, is_event_day: bool}
     */
    private function predictedArrivalsForDate(Carbon $date): array
    {
        $isEventDay = EventDay::whereDate('date', $date)->exists();
        $sameWeekdayDates = $this->recentDatesForWeekday($date, $date->dayOfWeek, self::LOOKBACK_WEEKS);

        if ($sameWeekdayDates->count() >= 2) {
            $totals = $this->dailyTotals($sameWeekdayDates);

            $weightSum = 0;
            $weighted = 0;

            foreach ($sameWeekdayDates->values() as $i => $d) {
                $weight = 1 / ($i + 1);
                $weighted += ($totals[$d->toDateString()] ?? 0) * $weight;
                $weightSum += $weight;
            }

            $base = $weightSum > 0 ? $weighted / $weightSum : 0;
        } else {
            $anyDates = AnalyticsSnapshot::where('date', '>=', $date->copy()->subDays(self::TREND_DAYS)->toDateString())
                ->where('date', '<', $date->toDateString())
                ->select('date')->distinct()->get()
                ->pluck('date')->map(fn (string $d) => Carbon::parse($d));

            $totals = $this->dailyTotals($anyDates);
            $base = $totals->isEmpty() ? 0 : $totals->avg();
        }

        $predicted = $isEventDay ? $base * self::EVENT_DAY_FACTOR : $base;

        return [
            'predicted_arrivals' => (int) round(max($predicted, 0)),
            'is_event_day' => $isEventDay,
        ];
    }

    /**
     * Distinct past dates (before $before, capped to LOOKBACK_CAP_WEEKS back)
     * matching $dayOfWeek, most recent first.
     *
     * @return Collection<int, Carbon>
     */
    private function recentDatesForWeekday(Carbon $before, int $dayOfWeek, int $limit): Collection
    {
        return AnalyticsSnapshot::where('date', '>=', $before->copy()->subWeeks(self::LOOKBACK_CAP_WEEKS)->toDateString())
            ->where('date', '<', $before->toDateString())
            ->select('date')->distinct()->get()
            ->pluck('date')
            ->map(fn (string $d) => Carbon::parse($d))
            ->filter(fn (Carbon $d) => $d->dayOfWeek === $dayOfWeek)
            ->sortByDesc(fn (Carbon $d) => $d->toDateString())
            ->take($limit)
            ->values();
    }

    /**
     * @param  Collection<int, Carbon>  $dates
     * @return Collection<string, int> date string => summed arrival_count
     */
    private function dailyTotals(Collection $dates): Collection
    {
        if ($dates->isEmpty()) {
            return collect();
        }

        return AnalyticsSnapshot::whereIn('date', $dates->map(fn (Carbon $d) => $d->toDateString()))
            ->selectRaw('date, SUM(arrival_count) as total')
            ->groupBy('date')
            ->pluck('total', 'date');
    }

    /**
     * Average, per hour, of that hour's share of the day's total arrivals —
     * across recent same-weekday dates. Used to split a day-level forecast
     * into an hourly breakdown.
     *
     * @return array<int, float> hour => share (0..1)
     */
    private function hourlySharesForWeekday(int $dayOfWeek): array
    {
        $dates = AnalyticsSnapshot::where('date', '>=', Carbon::today()->subWeeks(self::LOOKBACK_CAP_WEEKS)->toDateString())
            ->select('date')->distinct()->get()
            ->pluck('date')
            ->map(fn (string $d) => Carbon::parse($d))
            ->filter(fn (Carbon $d) => $d->dayOfWeek === $dayOfWeek);

        if ($dates->isEmpty()) {
            return [];
        }

        $byDate = AnalyticsSnapshot::whereIn('date', $dates->map(fn (Carbon $d) => $d->toDateString()))
            ->get()
            ->groupBy(fn (AnalyticsSnapshot $row) => $row->date);

        $hourShares = [];

        foreach ($byDate as $dateRows) {
            $total = $dateRows->sum('arrival_count');

            if ($total <= 0) {
                continue;
            }

            foreach ($dateRows as $row) {
                $hourShares[$row->hour][] = $row->arrival_count / $total;
            }
        }

        return collect($hourShares)->map(fn (array $shares) => array_sum($shares) / count($shares))->all();
    }

    /**
     * @param  array<int, array{hour: int, arrival_count: int}>  $hours
     */
    private function busiestHour(array $hours): ?int
    {
        if (empty($hours)) {
            return null;
        }

        return collect($hours)->sortByDesc('arrival_count')->first()['hour'];
    }
}
