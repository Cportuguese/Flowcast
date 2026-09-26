<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsSnapshot;
use App\Models\ClinicSchedule;
use App\Models\EventDay;
use App\Models\MlModel;
use App\Models\QueueEntry;
use App\Services\Ml\ModelEvaluator;
use App\Services\Ml\TrainedModel;
use App\Services\Ml\VolumePredictor;
use App\Services\Ml\WaitTimePredictor;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AnalyticsController extends Controller
{
    /** Hard cap on how far back history queries scan. */
    private const LOOKBACK_CAP_WEEKS = 26;

    /** Trailing window shown on the volume/wait-time trend charts. */
    private const TREND_DAYS = 28;

    /** How far back the online predicted-vs-actual comparison looks. */
    private const ONLINE_WINDOW_DAYS = 30;

    /** Personnel counts offered by the staffing what-if. */
    private const WHAT_IF_STAFF_LEVELS = [1, 2, 3];

    public function __construct(
        private readonly VolumePredictor $volumePredictor,
        private readonly WaitTimePredictor $waitTimePredictor,
    ) {}

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
            'upcoming_hours' => $this->upcomingHours($today),
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
            $forecast = $this->volumePredictor->predict($date);

            $series[] = [
                'date' => $date->toDateString(),
                'type' => 'forecast',
                'predicted_arrivals' => $forecast['predicted_arrivals'],
                'low' => $forecast['low'],
                'high' => $forecast['high'],
                'is_event_day' => $forecast['is_event_day'],
                'source' => $forecast['source'],
            ];
        }

        return response()->json([
            'days' => $days,
            'series' => $series,
            'model' => $this->modelSummary(MlModel::KEY_VOLUME),
        ]);
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

        $forecast = $this->volumePredictor->predict($date);
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
     * Accuracy and interpretation of the active models, for the admin
     * analytics page. Admin-only: it exposes how the clinic is staffed and
     * how it performs, which is management information rather than
     * operational data.
     */
    public function modelPerformance()
    {
        return response()->json([
            'wait_time' => $this->modelDetail(MlModel::KEY_WAIT_TIME),
            'volume' => $this->modelDetail(MlModel::KEY_VOLUME),
            'online' => $this->onlineAccuracy(),
        ]);
    }

    /**
     * Predicted wait for someone joining the queue now, at each possible
     * personnel count.
     *
     * Because the number of personnel on duty is a model input rather than
     * something inferred after the fact, asking "what if a second nurse
     * were on" costs nothing extra — it is the same model queried with one
     * feature changed.
     */
    public function staffingWhatIf(Request $request)
    {
        $validated = $request->validate([
            'people_ahead' => ['nullable', 'integer', 'between:0,200'],
        ]);

        $today = Carbon::today();
        $peopleAhead = $validated['people_ahead'] ?? QueueEntry::where('queue_date', $today)
            ->where('status', 'waiting')
            ->count();

        $scenarios = [];

        foreach (self::WHAT_IF_STAFF_LEVELS as $staff) {
            $estimate = $this->waitTimePredictor->predict($today, $peopleAhead, staffOverride: $staff);

            $scenarios[] = [
                'active_staff' => $staff,
                'estimated_wait_minutes' => $estimate['minutes'],
                'estimated_wait_low' => $estimate['low'],
                'estimated_wait_high' => $estimate['high'],
            ];
        }

        return response()->json([
            'date' => $today->toDateString(),
            'people_ahead' => $peopleAhead,
            'source' => $scenarios === [] ? null : $this->waitTimePredictor->predict($today, $peopleAhead)['source'],
            'scenarios' => $scenarios,
        ]);
    }

    /**
     * Short provenance stamp attached to forecast responses so a chart can
     * say whether it is showing a trained model or the fallback heuristic.
     *
     * @return array{is_trained: bool, version: int|null, trained_at: string|null, mae: float|null, baseline_mae: float|null}
     */
    private function modelSummary(string $key): array
    {
        $model = MlModel::active($key);

        return [
            'is_trained' => $model !== null,
            'version' => $model?->version,
            'trained_at' => $model?->trained_at?->toIso8601String(),
            'mae' => $model?->metrics['mae'] ?? null,
            'baseline_mae' => $model?->metrics['baseline_mae'] ?? null,
        ];
    }

    /**
     * Everything the model-performance card shows for one model: how it was
     * fitted, how it scored against the heuristic it replaced, and which
     * features carry the weight.
     *
     * Returns a populated shape with nulls rather than an empty object when
     * nothing has been trained yet, so the page renders an honest "not
     * trained" state instead of breaking.
     *
     * @return array<string, mixed>
     */
    private function modelDetail(string $key): array
    {
        $model = MlModel::active($key);

        if (! $model) {
            return [
                'is_trained' => false,
                'version' => null,
                'trained_at' => null,
                'algorithm' => null,
                'lambda' => null,
                'training_rows' => 0,
                'holdout_rows' => 0,
                'metrics' => null,
                'feature_importances' => [],
            ];
        }

        return [
            'is_trained' => true,
            'version' => $model->version,
            'trained_at' => $model->trained_at->toIso8601String(),
            'algorithm' => $model->algorithm,
            'lambda' => $model->lambda,
            'training_rows' => $model->training_rows,
            'holdout_rows' => $model->holdout_rows,
            'metrics' => $model->metrics,
            'feature_importances' => TrainedModel::fromModel($model)->featureImportances(),
        ];
    }

    /**
     * Predicted-vs-actual for the estimates actually shown to patients.
     *
     * These come from `queue_entries.predicted_wait_minutes`, written when
     * the patient joined, so they are genuinely out of sample — unlike a
     * holdout, which is reconstructed after the fact. The list is empty
     * until real check-ins have happened under a trained model.
     *
     * @return array{window_days: int, points: array<int, array{date: string, queue_number: int, predicted: int, actual: int}>, metrics: array<string, float|int|null>}
     */
    private function onlineAccuracy(): array
    {
        $entries = QueueEntry::query()
            ->whereNotNull('predicted_wait_minutes')
            ->whereNotNull('called_at')
            ->where('queue_date', '>=', Carbon::today()->subDays(self::ONLINE_WINDOW_DAYS)->toDateString())
            ->orderBy('queue_date')
            ->orderBy('queue_number')
            ->get();

        $points = $entries->map(fn (QueueEntry $entry) => [
            'date' => Carbon::parse($entry->queue_date)->toDateString(),
            'queue_number' => $entry->queue_number,
            'predicted' => (int) $entry->predicted_wait_minutes,
            'actual' => (int) round($entry->joined_at->diffInMinutes($entry->called_at)),
        ])->values();

        return [
            'window_days' => self::ONLINE_WINDOW_DAYS,
            'points' => $points->all(),
            'metrics' => ModelEvaluator::metrics(
                $points->pluck('actual')->map(fn (int $value): float => (float) $value)->all(),
                $points->pluck('predicted')->map(fn (int $value): float => (float) $value)->all(),
            ),
        ];
    }

    /**
     * Per-hour arrival forecast for the rest of today, each with the wait a
     * patient arriving in that hour should expect.
     *
     * The wait now comes from the model rather than arrivals x mean consult
     * time, so it reflects how many personnel are on duty and where in the
     * day the hour falls — the two things the old multiplication ignored.
     *
     * @return array<int, array{hour: int, label: string, predicted_arrivals: int, estimated_wait_minutes: int, estimated_wait_low: int, estimated_wait_high: int}>
     */
    private function upcomingHours(Carbon $today): array
    {
        $schedule = ClinicSchedule::where('day_of_week', $today->dayOfWeek)->where('is_active', true)->first();

        if (! $schedule) {
            return [];
        }

        $endHour = (int) Carbon::parse($schedule->end_time)->format('H');
        $predictedTotal = $this->volumePredictor->predict($today)['predicted_arrivals'];
        $shares = $this->hourlySharesForWeekday($today->dayOfWeek);

        $upcoming = [];

        for ($hour = Carbon::now()->hour + 1; $hour < $endHour; $hour++) {
            $predictedArrivals = (int) round($predictedTotal * ($shares[$hour] ?? 0));
            $estimate = $this->waitTimePredictor->predict(
                $today,
                $predictedArrivals,
                at: $today->copy()->setTime($hour, 0),
            );

            $upcoming[] = [
                'hour' => $hour,
                'label' => Carbon::createFromTime($hour)->format('g A'),
                'predicted_arrivals' => $predictedArrivals,
                'estimated_wait_minutes' => $estimate['minutes'],
                'estimated_wait_low' => $estimate['low'],
                'estimated_wait_high' => $estimate['high'],
            ];
        }

        return $upcoming;
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

        return (int) round($isEventDay ? $base * VolumePredictor::EVENT_DAY_FACTOR : $base);
    }

    /**
     * Average, per hour, of that hour's share of the day's total arrivals —
     * across recent same-weekday dates. Used to split a day-level forecast
     * into an hourly breakdown.
     *
     * Shares are Laplace-smoothed: every hour is counted as if it had been
     * observed once more than it was. With thin history a single freak hour
     * would otherwise claim most of a day's forecast, and an hour that
     * happened to see nobody would be predicted to stay empty forever.
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

        $counts = [];
        $days = 0;

        foreach ($byDate as $dateRows) {
            $total = $dateRows->sum('arrival_count');

            if ($total <= 0) {
                continue;
            }

            $days++;

            foreach ($dateRows as $row) {
                $counts[$row->hour] = ($counts[$row->hour] ?? 0) + $row->arrival_count;
            }
        }

        if ($counts === [] || $days === 0) {
            return [];
        }

        $hours = count($counts);
        $smoothedTotal = array_sum($counts) + $hours;

        return collect($counts)
            ->map(fn (int $count): float => ($count + 1) / $smoothedTotal)
            ->all();
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
