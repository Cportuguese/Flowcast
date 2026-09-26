<?php

namespace App\Services\Ml;

use App\Models\MlModel;
use InvalidArgumentException;

/**
 * An immutable fitted model: the learned coefficients plus the exact feature
 * scaling they were fitted against.
 *
 * Features are passed around as name-keyed maps rather than bare vectors, and
 * this object is the only place that turns a map into an ordered vector. A
 * feature added or reordered on the extraction side therefore fails loudly
 * instead of silently shifting every coefficient by one position.
 */
class TrainedModel
{
    /**
     * Normal-deviate multiplier for a p10/p90 prediction interval — the
     * central 80% of a normal residual distribution.
     */
    public const INTERVAL_Z = 1.2816;

    /**
     * @param  array<int, string>  $featureNames
     * @param  array<int, float>  $coefficients  standardized-space weights, aligned to $featureNames
     * @param  array<int, float>  $featureMeans
     * @param  array<int, float>  $featureStds
     */
    public function __construct(
        public readonly array $featureNames,
        public readonly array $coefficients,
        public readonly float $intercept,
        public readonly array $featureMeans,
        public readonly array $featureStds,
        public readonly float $lambda = 0.0,
        public readonly float $residualStd = 0.0,
    ) {}

    /**
     * Order a name-keyed feature map into the vector this model was fitted
     * against.
     *
     * @param  array<string, float|int|bool>  $features
     * @return array<int, float>
     */
    public function vectorize(array $features): array
    {
        $vector = [];

        foreach ($this->featureNames as $name) {
            if (! array_key_exists($name, $features)) {
                throw new InvalidArgumentException("Missing feature [{$name}] for prediction.");
            }

            $vector[] = (float) $features[$name];
        }

        return $vector;
    }

    /**
     * @param  array<string, float|int|bool>  $features
     */
    public function predict(array $features): float
    {
        $vector = $this->vectorize($features);
        $prediction = $this->intercept;

        foreach ($this->coefficients as $i => $coefficient) {
            $std = $this->featureStds[$i] ?? 1.0;
            $standardized = $std > 0.0 ? ($vector[$i] - $this->featureMeans[$i]) / $std : 0.0;
            $prediction += $coefficient * $standardized;
        }

        return $prediction;
    }

    /**
     * Point prediction with a p10/p90 band derived from the holdout residual
     * spread. Clamped at zero: neither a wait nor an arrival count can be
     * negative, however far the linear fit extrapolates.
     *
     * @param  array<string, float|int|bool>  $features
     * @return array{point: float, low: float, high: float}
     */
    public function predictWithInterval(array $features): array
    {
        $point = max($this->predict($features), 0.0);
        $margin = self::INTERVAL_Z * $this->residualStd;

        return [
            'point' => $point,
            'low' => max($point - $margin, 0.0),
            'high' => max($point + $margin, 0.0),
        ];
    }

    /**
     * Standardized coefficients, largest absolute weight first. Because every
     * feature was scaled to unit variance before fitting, these are directly
     * comparable: this is the model's own account of what drives the target.
     *
     * @return array<int, array{feature: string, coefficient: float, importance: float}>
     */
    public function featureImportances(): array
    {
        $importances = [];

        foreach ($this->featureNames as $i => $name) {
            $coefficient = $this->coefficients[$i] ?? 0.0;

            $importances[] = [
                'feature' => $name,
                'coefficient' => round($coefficient, 4),
                'importance' => round(abs($coefficient), 4),
            ];
        }

        usort($importances, static fn (array $a, array $b): int => $b['importance'] <=> $a['importance']);

        return $importances;
    }

    public static function fromModel(MlModel $model): self
    {
        return new self(
            featureNames: $model->feature_names,
            coefficients: array_map('floatval', $model->coefficients),
            intercept: (float) $model->intercept,
            featureMeans: array_map('floatval', $model->feature_means),
            featureStds: array_map('floatval', $model->feature_stds),
            lambda: (float) $model->lambda,
            residualStd: (float) ($model->residual_std ?? 0.0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'feature_names' => $this->featureNames,
            'coefficients' => $this->coefficients,
            'intercept' => $this->intercept,
            'feature_means' => $this->featureMeans,
            'feature_stds' => $this->featureStds,
            'lambda' => $this->lambda,
            'residual_std' => $this->residualStd,
        ];
    }
}
