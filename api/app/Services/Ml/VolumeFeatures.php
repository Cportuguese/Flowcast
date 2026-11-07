<?php

namespace App\Services\Ml;

use App\Models\AnalyticsSnapshot;
use App\Models\EventDay;
use Illuminate\Support\Carbon;

/**
 * Feature extraction for the daily arrival-volume model.
 *
 * As with the wait-time model, `build()` is the single extraction path used
 * by both training and forecasting. It reads only the daily totals panel in
 * `analytics_snapshots`, which the nightly `analytics:snapshot` command
 * already maintains.
 */
class VolumeFeatures
{
    /**
     * Canonical feature order; coefficients are stored positionally against
     * this list.
     *
     * @var array<int, string>
     */
    public const FEATURE_NAMES = [
        'dow_2',
        'dow_3',
        'dow_4',
        'dow_5',
        'dow_6',
        'is_event_day',
        'lag_same_weekday_1',
        'lag_same_weekday_2',
        'roll7_mean',
        'roll28_mean',
        'trend_index',
    ];

    /**
     * The single extraction path shared by training and forecasting.
     *
     * The two same-weekday lags carry the weekly pattern, the two rolling
     * means carry the recent level, and `trend_index` — days since the first
     * record — lets the model express a term the existing recency-weighted
     * average cannot: a clinic getting steadily busier or quieter over the
     * semester.
     *
     * @return array<string, float> keyed by feature name, in FEATURE_NAMES order
     */
    public static function build(
        Carbon $date,
        bool $isEventDay,
        float $lagSameWeekday1,
        float $lagSameWeekday2,
        float $roll7Mean,
        float $roll28Mean,
        int $trendIndex,
    ): array {
        $features = [];
        $dayOfWeek = $date->dayOfWeek;

        /*
         * Monday is the omitted baseline: the clinic never opens on Sunday,
         * and with Sunday as the baseline the dummies always summed to one
         * and duplicated the intercept (see WaitTimeFeatures::build()).
         */
        for ($day = 2; $day <= 6; $day++) {
            $features["dow_{$day}"] = $dayOfWeek === $day ? 1.0 : 0.0;
        }

        $features['is_event_day'] = $isEventDay ? 1.0 : 0.0;
        $features['lag_same_weekday_1'] = $lagSameWeekday1;
        $features['lag_same_weekday_2'] = $lagSameWeekday2;
        $features['roll7_mean'] = $roll7Mean;
        $features['roll28_mean'] = $roll28Mean;
        $features['trend_index'] = (float) $trendIndex;

        return $features;
    }

    /**
     * Daily arrival totals from the snapshot panel, oldest first.
     *
     * @return array<string, int> date string => total arrivals
     */
    public static function dailyTotals(): array
    {
        return AnalyticsSnapshot::query()
            ->selectRaw('date, SUM(arrival_count) as total')
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('total', 'date')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }

    /**
     * Build the features for one date from the history preceding it.
     *
     * @param  array<string, int>  $history  date string => total, oldest first, strictly before $date
     * @return array<string, float>
     */
    public static function fromHistory(Carbon $date, bool $isEventDay, array $history, int $trendIndex): array
    {
        $sameWeekday = [];

        foreach (array_reverse($history, true) as $day => $total) {
            if (Carbon::parse($day)->dayOfWeek === $date->dayOfWeek) {
                $sameWeekday[] = (float) $total;
            }

            if (count($sameWeekday) === 2) {
                break;
            }
        }

        $values = array_values($history);
        $overallMean = $values === [] ? 0.0 : array_sum($values) / count($values);

        return self::build(
            date: $date,
            isEventDay: $isEventDay,
            lagSameWeekday1: $sameWeekday[0] ?? $overallMean,
            lagSameWeekday2: $sameWeekday[1] ?? ($sameWeekday[0] ?? $overallMean),
            roll7Mean: self::trailingMean($values, 7, $overallMean),
            roll28Mean: self::trailingMean($values, 28, $overallMean),
            trendIndex: $trendIndex,
        );
    }

    /**
     * @param  array<int, float|int>  $values  oldest first
     */
    private static function trailingMean(array $values, int $window, float $fallback): float
    {
        $slice = array_slice($values, -$window);

        return $slice === [] ? $fallback : array_sum($slice) / count($slice);
    }

    /**
     * One training row per clinic day that has at least two prior days of
     * history to build lags from, in chronological order.
     *
     * @return array{
     *     samples: array<int, array<int, float>>,
     *     targets: array<int, float>,
     *     dates: array<int, string>,
     *     feature_names: array<int, string>
     * }
     */
    public static function trainingSet(): array
    {
        $totals = self::dailyTotals();
        $eventDates = EventDay::query()->pluck('date')
            ->map(fn (Carbon $date): string => $date->toDateString())
            ->flip();

        $samples = [];
        $targets = [];
        $dates = [];
        $history = [];
        $index = 0;

        foreach ($totals as $date => $total) {
            if (count($history) >= 2) {
                $samples[] = array_values(self::fromHistory(
                    Carbon::parse($date),
                    $eventDates->has($date),
                    $history,
                    $index,
                ));
                $targets[] = (float) $total;
                $dates[] = $date;
            }

            $history[$date] = $total;
            $index++;
        }

        return [
            'samples' => $samples,
            'targets' => $targets,
            'dates' => $dates,
            'feature_names' => self::FEATURE_NAMES,
        ];
    }

    /**
     * The pre-existing recency-weighted same-weekday forecast, evaluated on
     * the holdout dates.
     *
     * The baseline is allowed to see each prior holdout day as it walks
     * forward, exactly as it would in production. That favours the baseline
     * slightly, which is the right way round: a model that still wins has
     * won against the heuristic at its best.
     *
     * @param  array<int, string>  $dates
     * @return array<int, float>
     */
    public static function baselinePredictions(array $dates): array
    {
        $predictor = app(VolumePredictor::class);

        return array_map(
            static fn (string $date): float => (float) $predictor->heuristicArrivals(Carbon::parse($date)),
            $dates,
        );
    }
}
