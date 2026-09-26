<?php

namespace Database\Factories;

use App\Models\MlModel;
use App\Services\Ml\VolumeFeatures;
use App\Services\Ml\WaitTimeFeatures;
use App\Services\Ml\WaitTimePredictor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MlModel>
 */
class MlModelFactory extends Factory
{
    /**
     * A serveable wait-time model by default: coefficients are arbitrary but
     * internally consistent, so anything built from this factory produces
     * real predictions rather than divide-by-zero noise.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return array_merge($this->weightsFor(WaitTimeFeatures::FEATURE_NAMES, self::WAIT_TIME_WEIGHTS), [
            'key' => MlModel::KEY_WAIT_TIME,
            'version' => 1,
            'algorithm' => 'ridge_regression',
            'intercept' => 12.0,
            'lambda' => 0.1,
            'training_rows' => WaitTimePredictor::MIN_TRAINING_ROWS * 2,
            'holdout_rows' => 100,
            'metrics' => ['mae' => 4.2, 'rmse' => 6.1, 'r2' => 0.61, 'mape' => 22.5, 'n' => 100],
            'residual_std' => 5.0,
            'is_active' => false,
            'trained_at' => now(),
        ]);
    }

    /**
     * The model the predictors will actually serve.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => true]);
    }

    /**
     * Fitted on too little history to be trusted, so the predictors should
     * fall back to the heuristic.
     */
    public function undertrained(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => true,
            'training_rows' => WaitTimePredictor::MIN_TRAINING_ROWS - 1,
        ]);
    }

    public function volume(): static
    {
        return $this->state(fn (array $attributes): array => array_merge(
            $this->weightsFor(VolumeFeatures::FEATURE_NAMES),
            ['key' => MlModel::KEY_VOLUME, 'intercept' => 30.0],
        ));
    }

    /**
     * Weights shaped like the ones training actually produces: the
     * queue-per-nurse ratio dominates and an extra nurse pulls the estimate
     * down. A flat coefficient for every feature would make a model that
     * technically predicts but behaves nothing like the real one, so tests
     * built on it would prove nothing.
     *
     * @var array<string, float>
     */
    private const WAIT_TIME_WEIGHTS = [
        'people_ahead_per_staff' => 4.0,
        'people_ahead' => 1.0,
        'active_staff' => -1.5,
        'rolling_consult_mean' => 0.8,
        'in_consultation' => 0.5,
    ];

    /**
     * Unit means and stds keep standardized inference a no-op, so a test can
     * reason about the coefficients directly.
     *
     * @param  array<int, string>  $names
     * @param  array<string, float>  $weights
     * @return array<string, mixed>
     */
    private function weightsFor(array $names, array $weights = []): array
    {
        return [
            'feature_names' => $names,
            'coefficients' => array_map(
                static fn (string $name): float => $weights[$name] ?? 0.25,
                $names,
            ),
            'feature_means' => array_fill(0, count($names), 0.0),
            'feature_stds' => array_fill(0, count($names), 1.0),
        ];
    }
}
