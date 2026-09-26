<?php

namespace Tests\Unit;

use App\Services\Ml\RidgeRegression;
use App\Services\Ml\TrainedModel;
use PHPUnit\Framework\TestCase;

class RidgeRegressionTest extends TestCase
{
    /**
     * Coefficients are learned in standardized space, so a raw weight is
     * recovered by dividing the fitted coefficient by that column's
     * standard deviation.
     */
    private function rawCoefficient(TrainedModel $model, int $index): float
    {
        $std = $model->featureStds[$index];

        return $std > 0.0 ? $model->coefficients[$index] / $std : 0.0;
    }

    public function test_it_recovers_known_coefficients_from_noiseless_data(): void
    {
        /** y = 5 + 3*x1 - 2*x2, exactly. */
        $samples = [];
        $targets = [];

        for ($x1 = 0; $x1 < 8; $x1++) {
            for ($x2 = 0; $x2 < 8; $x2++) {
                $samples[] = [(float) $x1, (float) $x2];
                $targets[] = 5.0 + (3.0 * $x1) - (2.0 * $x2);
            }
        }

        $model = RidgeRegression::fit($samples, $targets, 0.0, ['x1', 'x2']);

        $this->assertEqualsWithDelta(3.0, $this->rawCoefficient($model, 0), 1e-8);
        $this->assertEqualsWithDelta(-2.0, $this->rawCoefficient($model, 1), 1e-8);
        $this->assertEqualsWithDelta(62.0, $model->predict(['x1' => 20.0, 'x2' => 1.5]), 1e-6);
    }

    public function test_lambda_zero_matches_the_ols_closed_form(): void
    {
        $samples = [[1.0, 2.0], [2.0, 1.0], [3.0, 4.0], [4.0, 3.0], [5.0, 7.0], [6.0, 5.0]];
        $targets = [3.1, 2.9, 6.2, 5.8, 9.9, 8.4];

        $model = RidgeRegression::fit($samples, $targets, 0.0, ['x1', 'x2']);

        /**
         * Closed form for two centred predictors:
         *   b1 = (S22*S1y - S12*S2y) / (S11*S22 - S12^2)
         */
        $n = count($samples);
        $meanX1 = array_sum(array_column($samples, 0)) / $n;
        $meanX2 = array_sum(array_column($samples, 1)) / $n;
        $meanY = array_sum($targets) / $n;

        $s11 = $s22 = $s12 = $s1y = $s2y = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $d1 = $samples[$i][0] - $meanX1;
            $d2 = $samples[$i][1] - $meanX2;
            $dy = $targets[$i] - $meanY;
            $s11 += $d1 * $d1;
            $s22 += $d2 * $d2;
            $s12 += $d1 * $d2;
            $s1y += $d1 * $dy;
            $s2y += $d2 * $dy;
        }

        $denominator = ($s11 * $s22) - ($s12 ** 2);

        $this->assertEqualsWithDelta(
            (($s22 * $s1y) - ($s12 * $s2y)) / $denominator,
            $this->rawCoefficient($model, 0),
            1e-8,
        );
        $this->assertEqualsWithDelta(
            (($s11 * $s2y) - ($s12 * $s1y)) / $denominator,
            $this->rawCoefficient($model, 1),
            1e-8,
        );
    }

    public function test_a_larger_penalty_shrinks_coefficients_toward_zero(): void
    {
        $samples = [[1.0, 2.0], [2.0, 1.0], [3.0, 4.0], [4.0, 3.0], [5.0, 7.0], [6.0, 5.0]];
        $targets = [3.1, 2.9, 6.2, 5.8, 9.9, 8.4];

        $magnitudes = [];

        foreach ([0.0, 1.0, 10.0, 100.0] as $lambda) {
            $model = RidgeRegression::fit($samples, $targets, $lambda, ['x1', 'x2']);
            $magnitudes[] = abs($model->coefficients[0]) + abs($model->coefficients[1]);
        }

        for ($i = 1; $i < count($magnitudes); $i++) {
            $this->assertLessThan($magnitudes[$i - 1], $magnitudes[$i]);
        }
    }

    public function test_the_intercept_is_the_target_mean_and_is_never_penalised(): void
    {
        $samples = [[1.0], [2.0], [3.0], [4.0]];
        $targets = [10.0, 20.0, 30.0, 40.0];

        foreach ([0.0, 1.0, 1000.0] as $lambda) {
            $model = RidgeRegression::fit($samples, $targets, $lambda, ['x']);

            $this->assertEqualsWithDelta(25.0, $model->intercept, 1e-9);
        }
    }

    public function test_a_constant_column_is_neutralised_rather_than_dividing_by_zero(): void
    {
        $samples = [[1.0, 7.0], [2.0, 7.0], [3.0, 7.0], [4.0, 7.0]];
        $targets = [2.0, 4.0, 6.0, 8.0];

        $model = RidgeRegression::fit($samples, $targets, 0.1, ['x', 'constant']);

        $this->assertSame(0.0, $model->featureStds[1]);
        $this->assertFalse(is_nan($model->predict(['x' => 5.0, 'constant' => 7.0])));
    }

    public function test_it_rejects_mismatched_samples_and_targets(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RidgeRegression::fit([[1.0], [2.0]], [1.0], 0.0, ['x']);
    }
}
