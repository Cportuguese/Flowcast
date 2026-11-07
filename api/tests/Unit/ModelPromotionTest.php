<?php

namespace Tests\Unit;

use App\Console\Commands\TrainMlModels;
use App\Services\Ml\ModelEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * A trained model replaces the simple formula only when it is actually
 * better on days it never saw — and never replaces a better model.
 */
class ModelPromotionTest extends TestCase
{
    public function test_a_model_that_beats_the_formula_is_switched_on(): void
    {
        $this->assertTrue(TrainMlModels::shouldPromote(5.4, 6.2, null));
    }

    public function test_a_model_that_only_ties_or_loses_to_the_formula_is_not(): void
    {
        $this->assertFalse(TrainMlModels::shouldPromote(4.42, 4.42, null));
        $this->assertFalse(TrainMlModels::shouldPromote(4.7, 4.4, null));
    }

    public function test_a_retrain_never_replaces_a_more_accurate_model(): void
    {
        $this->assertFalse(TrainMlModels::shouldPromote(5.6, 6.2, 5.4));
        $this->assertTrue(TrainMlModels::shouldPromote(5.3, 6.2, 5.4));
    }

    public function test_a_win_within_the_noise_of_the_comparison_is_not_enough(): void
    {
        // 6.421 vs 6.484 on 31 held-out days, with 0.6 of noise: a tie, not a win.
        $this->assertFalse(TrainMlModels::shouldPromote(6.421, 6.484, null, 0.6));
        // 5.228 vs 6.127 with 0.12 of noise: clearly better.
        $this->assertTrue(TrainMlModels::shouldPromote(5.228, 6.127, null, 0.12));
        // The edge has to exceed twice the noise, not merely equal it.
        $this->assertFalse(TrainMlModels::shouldPromote(5.0, 6.0, null, 0.5));
    }

    public function test_the_row_by_row_difference_and_its_noise(): void
    {
        // The model is 1 better on every row: a certain win with no noise.
        $sure = ModelEvaluator::maeDifference([10, 10, 10], [11, 9, 11], [12, 8, 12]);
        $this->assertEqualsWithDelta(1.0, $sure['difference'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $sure['standard_error'], 1e-9);

        // Better on one row, worse on another: no difference, and noisy.
        $tie = ModelEvaluator::maeDifference([10, 10], [11, 13], [13, 11]);
        $this->assertEqualsWithDelta(0.0, $tie['difference'], 1e-9);
        $this->assertGreaterThan(0.0, $tie['standard_error']);
    }

    public function test_missing_metrics_never_promote(): void
    {
        $this->assertFalse(TrainMlModels::shouldPromote(null, 6.2, null));
        $this->assertFalse(TrainMlModels::shouldPromote(5.0, null, null));
    }
}
