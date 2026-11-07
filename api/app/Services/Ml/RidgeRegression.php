<?php

namespace App\Services\Ml;

use InvalidArgumentException;

/**
 * Ridge-regularized linear regression solved by normal equations:
 *
 *     beta = (XT X + lambda I)^-1 XT y
 *
 * Two deliberate choices, both of which the write-up depends on:
 *
 * 1. Columns are standardized to zero mean and unit variance before fitting.
 *    A ridge penalty is not scale-invariant, so without this the penalty
 *    would fall hardest on whichever feature happened to use the largest
 *    units. It also makes the resulting coefficients comparable to each
 *    other, which is what lets them be read as feature importances.
 * 2. The target is centred and the intercept recovered as mean(y), so the
 *    intercept is never shrunk toward zero by the penalty.
 *
 * The lambda I term also guarantees the inverse exists for lambda > 0 even
 * when features are perfectly collinear, which is the practical reason to
 * prefer ridge over plain OLS here: the clinic is closed on Sundays, so the
 * six weekday dummies always add up to one and duplicate the intercept.
 */
class RidgeRegression
{
    /**
     * Columns with (near) zero variance carry no information; they are
     * standardized to a constant zero rather than dividing by ~0.
     */
    private const MIN_STD = 1e-8;

    /**
     * @param  array<int, array<int, float>>  $samples  one row per observation
     * @param  array<int, float>  $targets
     * @param  array<int, string>  $featureNames
     */
    public static function fit(array $samples, array $targets, float $lambda, array $featureNames): TrainedModel
    {
        $rows = count($samples);

        if ($rows === 0) {
            throw new InvalidArgumentException('Cannot fit a model on an empty dataset.');
        }

        if ($rows !== count($targets)) {
            throw new InvalidArgumentException('Sample and target counts differ.');
        }

        $columns = count($samples[0]);

        if ($columns !== count($featureNames)) {
            throw new InvalidArgumentException('Feature name count does not match the sample width.');
        }

        [$means, $stds] = self::columnStatistics($samples, $columns, $rows);
        $standardized = self::standardize($samples, $means, $stds);

        $targetMean = array_sum($targets) / $rows;
        $centredTargets = array_map(static fn (float $y): float => $y - $targetMean, $targets);

        $transposed = Matrix::transpose($standardized);
        $gram = Matrix::multiply($transposed, $standardized);

        for ($i = 0; $i < $columns; $i++) {
            $gram[$i][$i] += $lambda;
        }

        $coefficients = Matrix::matVec(
            Matrix::inverse($gram),
            Matrix::matVec($transposed, $centredTargets),
        );

        return new TrainedModel(
            featureNames: $featureNames,
            coefficients: $coefficients,
            intercept: $targetMean,
            featureMeans: $means,
            featureStds: $stds,
            lambda: $lambda,
        );
    }

    /**
     * @param  array<int, array<int, float>>  $samples
     * @return array{0: array<int, float>, 1: array<int, float>}
     */
    private static function columnStatistics(array $samples, int $columns, int $rows): array
    {
        $means = [];
        $stds = [];

        for ($j = 0; $j < $columns; $j++) {
            $sum = 0.0;

            foreach ($samples as $row) {
                $sum += (float) $row[$j];
            }

            $mean = $sum / $rows;
            $variance = 0.0;

            foreach ($samples as $row) {
                $variance += (((float) $row[$j]) - $mean) ** 2;
            }

            $std = sqrt($variance / $rows);

            $means[$j] = $mean;
            $stds[$j] = $std < self::MIN_STD ? 0.0 : $std;
        }

        return [$means, $stds];
    }

    /**
     * @param  array<int, array<int, float>>  $samples
     * @param  array<int, float>  $means
     * @param  array<int, float>  $stds
     * @return array<int, array<int, float>>
     */
    private static function standardize(array $samples, array $means, array $stds): array
    {
        $standardized = [];

        foreach ($samples as $i => $row) {
            $scaled = [];

            foreach ($means as $j => $mean) {
                $scaled[$j] = $stds[$j] > 0.0 ? (((float) $row[$j]) - $mean) / $stds[$j] : 0.0;
            }

            $standardized[$i] = $scaled;
        }

        return $standardized;
    }
}
