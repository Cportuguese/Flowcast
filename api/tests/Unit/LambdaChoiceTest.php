<?php

namespace Tests\Unit;

use App\Console\Commands\TrainMlModels;
use App\Services\Ml\ModelEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Picking the ridge penalty: among penalties that predict the held-out days
 * equally well (within one standard error of the best), the strongest wins.
 */
class LambdaChoiceTest extends TestCase
{
    /**
     * @param  array<int, array{0: float, 1: float}>  $rows  [lambda, mae], best MAE first
     * @return array<int, array{lambda: float, mae: float, model: null}>
     */
    private function sweep(array $rows): array
    {
        return array_map(fn (array $row) => ['lambda' => $row[0], 'mae' => $row[1], 'model' => null], $rows);
    }

    public function test_the_strongest_penalty_within_one_standard_error_is_chosen(): void
    {
        $sweep = $this->sweep([[0.0, 5.220], [0.01, 5.220], [0.1, 5.220], [1.0, 5.221], [10.0, 5.235]]);

        $this->assertSame(10.0, TrainMlModels::chooseLambda($sweep, 0.16)['lambda']);
    }

    public function test_a_penalty_measurably_worse_than_the_best_is_not(): void
    {
        $sweep = $this->sweep([[0.1, 4.0], [0.0, 4.05], [1.0, 4.2], [10.0, 4.9]]);

        $this->assertSame(1.0, TrainMlModels::chooseLambda($sweep, 0.25)['lambda']);
        $this->assertSame(0.1, TrainMlModels::chooseLambda($sweep, 0.01)['lambda']);
    }

    public function test_without_a_standard_error_only_exact_ties_go_to_the_stronger_penalty(): void
    {
        $this->assertSame(0.01, TrainMlModels::chooseLambda($this->sweep([[0.0, 3.0], [0.01, 3.0], [1.0, 3.1]]), null)['lambda']);
        $this->assertSame(0.0, TrainMlModels::chooseLambda($this->sweep([[0.0, 3.0], [1.0, 3.1]]), null)['lambda']);
    }

    public function test_the_standard_error_of_the_mae_shrinks_with_more_held_out_rows(): void
    {
        // |errors| = 1, 3, 1, 3: mean 2, sample sd 1.1547, se = sd / sqrt(4).
        $this->assertEqualsWithDelta(0.5774, ModelEvaluator::maeStandardError([10, 10, 10, 10], [9, 13, 11, 7]), 1e-4);

        $actual = array_fill(0, 16, 10.0);
        $predicted = array_merge(...array_fill(0, 4, [9.0, 13.0, 11.0, 7.0]));
        $this->assertLessThan(0.3, ModelEvaluator::maeStandardError($actual, $predicted));

        $this->assertNull(ModelEvaluator::maeStandardError([1.0], [2.0]));
    }
}
