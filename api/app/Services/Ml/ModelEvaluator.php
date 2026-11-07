<?php

namespace App\Services\Ml;

/**
 * Regression metrics shared by the training command, the evaluation command
 * and the model-performance endpoint, so a number quoted in one place means
 * exactly the same thing in the others.
 */
class ModelEvaluator
{
    /**
     * MAE is the headline figure: it is in minutes (or arrivals), so it can
     * be stated plainly. RMSE is reported alongside it because it punishes
     * the large misses that MAE averages away. R-squared is measured against
     * the mean of the actuals, so a negative value is meaningful — it says
     * the model does worse than always guessing the average.
     *
     * @param  array<int, float>  $actual
     * @param  array<int, float>  $predicted
     * @return array{mae: float|null, rmse: float|null, r2: float|null, mape: float|null, n: int}
     */
    public static function metrics(array $actual, array $predicted): array
    {
        $actual = array_values($actual);
        $predicted = array_values($predicted);
        $count = min(count($actual), count($predicted));

        if ($count === 0) {
            return ['mae' => null, 'rmse' => null, 'r2' => null, 'mape' => null, 'n' => 0];
        }

        $absoluteError = 0.0;
        $squaredError = 0.0;
        $percentageError = 0.0;
        $percentageCount = 0;

        for ($i = 0; $i < $count; $i++) {
            $error = $actual[$i] - $predicted[$i];
            $absoluteError += abs($error);
            $squaredError += $error ** 2;

            if (abs($actual[$i]) > 1e-9) {
                $percentageError += abs($error / $actual[$i]);
                $percentageCount++;
            }
        }

        $mean = array_sum(array_slice($actual, 0, $count)) / $count;
        $totalSumSquares = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $totalSumSquares += ($actual[$i] - $mean) ** 2;
        }

        return [
            'mae' => round($absoluteError / $count, 3),
            'rmse' => round(sqrt($squaredError / $count), 3),
            'r2' => $totalSumSquares > 1e-9 ? round(1 - ($squaredError / $totalSumSquares), 4) : null,
            'mape' => $percentageCount > 0 ? round(100 * $percentageError / $percentageCount, 2) : null,
            'n' => $count,
        ];
    }

    /**
     * Share of actuals that landed inside their predicted band. A p10-p90
     * interval should capture roughly 80%: materially less means the stated
     * range is overconfident, and quoting this alongside MAE is what makes
     * the range honest rather than decorative.
     *
     * @param  array<int, float>  $actual
     * @param  array<int, float>  $low
     * @param  array<int, float>  $high
     */
    public static function intervalCoverage(array $actual, array $low, array $high): ?float
    {
        $actual = array_values($actual);
        $low = array_values($low);
        $high = array_values($high);
        $count = min(count($actual), count($low), count($high));

        if ($count === 0) {
            return null;
        }

        $inside = 0;

        for ($i = 0; $i < $count; $i++) {
            if ($actual[$i] >= $low[$i] && $actual[$i] <= $high[$i]) {
                $inside++;
            }
        }

        return round(100 * $inside / $count, 2);
    }

    /**
     * Split a chronologically-ordered dataset at a whole-day boundary,
     * putting the most recent days in the holdout.
     *
     * This is never a random split. The data is a time series, and a random
     * split would let the model learn from a Tuesday afternoon to predict
     * that same Tuesday morning — inflating every metric while telling you
     * nothing about how it will do tomorrow. Splitting on a day boundary
     * rather than a row index additionally keeps a single clinic day from
     * appearing on both sides.
     *
     * @param  array<int, array<int, float>>  $samples
     * @param  array<int, float>  $targets
     * @param  array<int, string>  $dates  non-decreasing, aligned to $samples
     * @return array{
     *     train_samples: array<int, array<int, float>>,
     *     train_targets: array<int, float>,
     *     holdout_samples: array<int, array<int, float>>,
     *     holdout_targets: array<int, float>,
     *     holdout_dates: array<int, string>
     * }
     */
    public static function chronologicalSplit(array $samples, array $targets, array $dates, float $trainFraction = 0.8): array
    {
        $rows = count($samples);
        $cut = (int) floor($rows * $trainFraction);
        $boundaryDate = $dates[$cut] ?? null;

        while ($cut > 0 && ($dates[$cut - 1] ?? null) === $boundaryDate) {
            $cut--;
        }

        return [
            'train_samples' => array_slice($samples, 0, $cut),
            'train_targets' => array_slice($targets, 0, $cut),
            'holdout_samples' => array_slice($samples, $cut),
            'holdout_targets' => array_slice($targets, $cut),
            'holdout_dates' => array_slice($dates, $cut),
        ];
    }

    /**
     * Population standard deviation of the holdout residuals — the width the
     * prediction intervals are built from.
     *
     * @param  array<int, float>  $actual
     * @param  array<int, float>  $predicted
     */
    public static function residualStd(array $actual, array $predicted): float
    {
        $actual = array_values($actual);
        $predicted = array_values($predicted);
        $count = min(count($actual), count($predicted));

        if ($count === 0) {
            return 0.0;
        }

        $residuals = [];

        for ($i = 0; $i < $count; $i++) {
            $residuals[] = $actual[$i] - $predicted[$i];
        }

        $mean = array_sum($residuals) / $count;
        $variance = 0.0;

        foreach ($residuals as $residual) {
            $variance += ($residual - $mean) ** 2;
        }

        return sqrt($variance / $count);
    }

    /**
     * How much lower one set of predictions' MAE is than another's on the
     * same rows, and how precisely the holdout pins that down. Each row is
     * compared with itself (|baseline error| - |model error|), so a hard day
     * that both get wrong does not count as noise against the model.
     *
     * @param  array<int, float>  $actual
     * @param  array<int, float>  $model
     * @param  array<int, float>  $baseline
     * @return array{difference: float, standard_error: float|null} difference > 0 means the model's error is lower
     */
    public static function maeDifference(array $actual, array $model, array $baseline): array
    {
        $actual = array_values($actual);
        $model = array_values($model);
        $baseline = array_values($baseline);
        $count = min(count($actual), count($model), count($baseline));

        if ($count === 0) {
            return ['difference' => 0.0, 'standard_error' => null];
        }

        $differences = [];

        for ($i = 0; $i < $count; $i++) {
            $differences[] = abs($actual[$i] - $baseline[$i]) - abs($actual[$i] - $model[$i]);
        }

        $mean = array_sum($differences) / $count;

        if ($count < 2) {
            return ['difference' => $mean, 'standard_error' => null];
        }

        $variance = 0.0;

        foreach ($differences as $difference) {
            $variance += ($difference - $mean) ** 2;
        }

        return ['difference' => $mean, 'standard_error' => sqrt($variance / ($count - 1)) / sqrt($count)];
    }

    /**
     * How precisely a holdout pins down an MAE: the standard error of the
     * mean absolute error (the sample standard deviation of |error| over
     * √n). Two models whose MAEs differ by less than this did not really
     * differ on the days held out.
     *
     * @param  array<int, float>  $actual
     * @param  array<int, float>  $predicted
     */
    public static function maeStandardError(array $actual, array $predicted): ?float
    {
        $actual = array_values($actual);
        $predicted = array_values($predicted);
        $count = min(count($actual), count($predicted));

        if ($count < 2) {
            return null;
        }

        $errors = [];

        for ($i = 0; $i < $count; $i++) {
            $errors[] = abs($actual[$i] - $predicted[$i]);
        }

        $mean = array_sum($errors) / $count;
        $variance = 0.0;

        foreach ($errors as $error) {
            $variance += ($error - $mean) ** 2;
        }

        return sqrt($variance / ($count - 1)) / sqrt($count);
    }
}
