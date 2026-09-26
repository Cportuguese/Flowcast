<?php

namespace App\Services\Ml;

use App\Models\AnalyticsSnapshot;
use App\Models\ClinicSchedule;
use App\Models\EventDay;
use App\Models\MlModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Forecasts a day's total arrivals.
 *
 * Prefers the trained volume model and falls back to the recency-weighted
 * same-weekday average that `AnalyticsController` used before this. That
 * heuristic now lives here, unchanged in behaviour, so the fallback path and
 * the reported baseline are the same code rather than two copies that can
 * drift apart.
 */
class VolumePredictor
{
    /** Below this many training rows the heuristic serves instead. */
    public const MIN_TRAINING_ROWS = 60;

    /** Cache key for the active model, cleared by `ml:train`. */
    public const CACHE_KEY = 'ml.active.volume';

    /**
     * Multiplier the heuristic applies on a flagged event day. Hand-picked,
     * never measured — replacing it with a learned `is_event_day` weight is
     * part of what the model is for.
     */
    public const EVENT_DAY_FACTOR = 1.5;

    /** How many same-weekday occurrences feed the heuristic. */
    private const LOOKBACK_WEEKS = 8;

    /** Hard cap on how far back history queries scan. */
    private const LOOKBACK_CAP_WEEKS = 26;

    /** Trailing window used when too little weekday-specific history exists. */
    private const TREND_DAYS = 28;

    private const CACHE_TTL_SECONDS = 600;

    /**
     * @return array{predicted_arrivals: int, low: int, high: int, is_event_day: bool, source: string, model_version: int|null}
     */
    public function predict(Carbon $date): array
    {
        $isEventDay = EventDay::whereDate('date', $date)->exists();

        if (! $this->isOpenOn($date)) {
            return [
                'predicted_arrivals' => 0,
                'low' => 0,
                'high' => 0,
                'is_event_day' => $isEventDay,
                'source' => 'closed',
                'model_version' => null,
            ];
        }

        $model = $this->activeModel();

        if (! $model) {
            return $this->heuristicResult($date, $isEventDay);
        }

        $totals = VolumeFeatures::dailyTotals();
        $history = array_filter(
            $totals,
            fn (string $day): bool => $day < $date->toDateString(),
            ARRAY_FILTER_USE_KEY,
        );

        if (count($history) < 2) {
            return $this->heuristicResult($date, $isEventDay);
        }

        $features = VolumeFeatures::fromHistory($date, $isEventDay, $history, count($history));
        $interval = TrainedModel::fromModel($model)->predictWithInterval($features);

        return [
            'predicted_arrivals' => (int) round($interval['point']),
            'low' => (int) round($interval['low']),
            'high' => (int) round($interval['high']),
            'is_event_day' => $isEventDay,
            'source' => 'model',
            'model_version' => $model->version,
        ];
    }

    /**
     * The pre-existing forecast: a recency-weighted average of recent
     * same-weekday totals, falling back to any recent day when too little
     * weekday-specific history exists, times the event-day factor.
     */
    public function heuristicArrivals(Carbon $date): int
    {
        $isEventDay = EventDay::whereDate('date', $date)->exists();
        $sameWeekdayDates = $this->recentDatesForWeekday($date, $date->dayOfWeek, self::LOOKBACK_WEEKS);

        if ($sameWeekdayDates->count() >= 2) {
            $totals = $this->dailyTotals($sameWeekdayDates);

            $weightSum = 0;
            $weighted = 0;

            foreach ($sameWeekdayDates->values() as $i => $day) {
                $weight = 1 / ($i + 1);
                $weighted += ($totals[$day->toDateString()] ?? 0) * $weight;
                $weightSum += $weight;
            }

            $base = $weightSum > 0 ? $weighted / $weightSum : 0;
        } else {
            $anyDates = AnalyticsSnapshot::where('date', '>=', $date->copy()->subDays(self::TREND_DAYS)->toDateString())
                ->where('date', '<', $date->toDateString())
                ->select('date')->distinct()->get()
                ->pluck('date')->map(fn (string $day): Carbon => Carbon::parse($day));

            $totals = $this->dailyTotals($anyDates);
            $base = $totals->isEmpty() ? 0 : $totals->avg();
        }

        return (int) round(max($isEventDay ? $base * self::EVENT_DAY_FACTOR : $base, 0));
    }

    /**
     * The active model, or null when none has been trained or the newest one
     * was fitted on too few days to serve.
     */
    public function activeModel(): ?MlModel
    {
        $cached = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $model = MlModel::active(MlModel::KEY_VOLUME);

            return ['id' => $model?->id];
        });

        if ($cached['id'] === null) {
            return null;
        }

        $model = MlModel::find($cached['id']);

        if (! $model || ! $model->is_active || $model->training_rows < self::MIN_TRAINING_ROWS) {
            return null;
        }

        return $model;
    }

    /**
     * Whether the clinic operates on that weekday.
     *
     * Weekday dummies use Sunday as the omitted baseline, so without this a
     * closed Sunday would be forecast at the model's intercept — a busy day
     * on a day nobody can visit.
     *
     * A clinic with no schedule configured at all is treated as open rather
     * than permanently closed: that means the hours are unknown, not that
     * the doors are shut, and returning zero for every future date would be
     * a worse answer than the forecast. The guard sits on the serving path
     * only; `heuristicArrivals()` is left exactly as it was because it is
     * the published baseline and must not quietly change.
     */
    private function isOpenOn(Carbon $date): bool
    {
        $active = ClinicSchedule::where('is_active', true);

        if ((clone $active)->doesntExist()) {
            return true;
        }

        return $active->where('day_of_week', $date->dayOfWeek)->exists();
    }

    /** Drop the cached active model so the next forecast re-reads it. */
    public static function forgetCachedModel(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array{predicted_arrivals: int, low: int, high: int, is_event_day: bool, source: string, model_version: int|null}
     */
    private function heuristicResult(Carbon $date, bool $isEventDay): array
    {
        $predicted = $this->heuristicArrivals($date);

        return [
            'predicted_arrivals' => $predicted,
            'low' => (int) round($predicted * 0.7),
            'high' => (int) round($predicted * 1.3),
            'is_event_day' => $isEventDay,
            'source' => 'heuristic',
            'model_version' => null,
        ];
    }

    /**
     * Distinct past dates matching $dayOfWeek, most recent first.
     *
     * @return Collection<int, Carbon>
     */
    private function recentDatesForWeekday(Carbon $before, int $dayOfWeek, int $limit): Collection
    {
        return AnalyticsSnapshot::where('date', '>=', $before->copy()->subWeeks(self::LOOKBACK_CAP_WEEKS)->toDateString())
            ->where('date', '<', $before->toDateString())
            ->select('date')->distinct()->get()
            ->pluck('date')
            ->map(fn (string $day): Carbon => Carbon::parse($day))
            ->filter(fn (Carbon $day): bool => $day->dayOfWeek === $dayOfWeek)
            ->sortByDesc(fn (Carbon $day): string => $day->toDateString())
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

        return AnalyticsSnapshot::whereIn('date', $dates->map(fn (Carbon $day): string => $day->toDateString()))
            ->selectRaw('date, SUM(arrival_count) as total')
            ->groupBy('date')
            ->pluck('total', 'date');
    }
}
