<?php

namespace App\Console\Commands;

use App\Models\MlModel;
use App\Services\Ml\ModelEvaluator;
use App\Services\Ml\RidgeRegression;
use App\Services\Ml\TrainedModel;
use App\Services\Ml\VolumeFeatures;
use App\Services\Ml\VolumePredictor;
use App\Services\Ml\WaitTimeFeatures;
use App\Services\Ml\WaitTimePredictor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

#[Signature('ml:train {key? : wait_time or volume; both when omitted} {--lambda= : skip the sweep and use this ridge penalty} {--dry-run : report without storing a new version}')]
#[Description('Fit the wait-time and volume models on the clinic\'s own history and store a new version.')]
class TrainMlModels extends Command
{
    /**
     * Ridge penalties tried against the holdout. Zero is included so the
     * sweep can report that no regularization was needed rather than
     * assuming it was.
     *
     * @var array<int, float>
     */
    private const LAMBDA_GRID = [0.0, 0.01, 0.1, 1.0, 10.0];

    /** Fewer rows than this and there is nothing worth fitting. */
    private const MIN_DATASET_ROWS = 40;

    public function handle(): int
    {
        $keys = $this->argument('key')
            ? [$this->argument('key')]
            : [MlModel::KEY_WAIT_TIME, MlModel::KEY_VOLUME];

        $failed = false;

        foreach ($keys as $key) {
            if (! in_array($key, [MlModel::KEY_WAIT_TIME, MlModel::KEY_VOLUME], true)) {
                $this->error("Unknown model key [{$key}].");

                return self::FAILURE;
            }

            $this->newLine();
            $this->info("Training [{$key}]...");

            if ($this->trainKey($key) === self::FAILURE) {
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function trainKey(string $key): int
    {
        $dataset = $key === MlModel::KEY_WAIT_TIME
            ? WaitTimeFeatures::trainingSet()
            : VolumeFeatures::trainingSet();

        $rows = count($dataset['samples']);

        if ($rows < self::MIN_DATASET_ROWS) {
            $this->warn("  Only {$rows} usable row(s); need at least ".self::MIN_DATASET_ROWS.'. Skipped.');

            return self::SUCCESS;
        }

        $split = ModelEvaluator::chronologicalSplit(
            $dataset['samples'],
            $dataset['targets'],
            $dataset['dates'],
        );

        if ($split['train_samples'] === [] || $split['holdout_samples'] === []) {
            $this->warn('  History spans too few distinct days to hold any out. Skipped.');

            return self::SUCCESS;
        }

        $sweep = $this->sweepLambda($split, $dataset['feature_names']);

        if ($sweep === []) {
            $this->error('  Every candidate fit failed — the feature matrix is degenerate.');

            return self::FAILURE;
        }

        $best = $sweep[0];
        $holdoutFit = $best['model'];
        $holdoutPredictions = $this->predictAll($holdoutFit, $split['holdout_samples']);
        $metrics = ModelEvaluator::metrics($split['holdout_targets'], $holdoutPredictions);
        $residualStd = ModelEvaluator::residualStd($split['holdout_targets'], $holdoutPredictions);

        $baseline = $key === MlModel::KEY_WAIT_TIME
            ? WaitTimeFeatures::baselinePredictions($split['holdout_samples'])
            : VolumeFeatures::baselinePredictions($split['holdout_dates']);
        $baselineMetrics = ModelEvaluator::metrics($split['holdout_targets'], $baseline);

        $coverage = $this->intervalCoverage($split['holdout_targets'], $holdoutPredictions, $residualStd);

        /** Refit on every row so the served model uses all available history. */
        $final = RidgeRegression::fit($dataset['samples'], $dataset['targets'], $best['lambda'], $dataset['feature_names']);

        $this->reportSweep($sweep);
        $this->reportMetrics($metrics, $baselineMetrics, $coverage, $rows, count($split['holdout_samples']));
        $this->reportImportances($final);

        if ($this->option('dry-run')) {
            $this->comment('  Dry run — nothing stored.');

            return self::SUCCESS;
        }

        $this->store($key, $final, $metrics, $baselineMetrics, $coverage, $residualStd, $sweep, $rows, count($split['holdout_samples']));

        return self::SUCCESS;
    }

    /**
     * Fit one model per candidate penalty and rank them by holdout MAE.
     *
     * A degenerate fit is skipped rather than fatal: an unpenalised fit on
     * collinear columns legitimately has no unique solution, and that is
     * precisely the case ridge exists to handle.
     *
     * @param  array<string, mixed>  $split
     * @param  array<int, string>  $featureNames
     * @return array<int, array{lambda: float, mae: float, model: TrainedModel}>
     */
    private function sweepLambda(array $split, array $featureNames): array
    {
        $grid = $this->option('lambda') !== null
            ? [(float) $this->option('lambda')]
            : self::LAMBDA_GRID;

        $results = [];

        foreach ($grid as $lambda) {
            try {
                $model = RidgeRegression::fit($split['train_samples'], $split['train_targets'], $lambda, $featureNames);
            } catch (RuntimeException $e) {
                $this->line("  lambda={$lambda}: skipped ({$e->getMessage()})");

                continue;
            }

            $predictions = $this->predictAll($model, $split['holdout_samples']);
            $mae = ModelEvaluator::metrics($split['holdout_targets'], $predictions)['mae'];

            $results[] = ['lambda' => $lambda, 'mae' => (float) $mae, 'model' => $model];
        }

        usort($results, static fn (array $a, array $b): int => $a['mae'] <=> $b['mae']);

        return $results;
    }

    /**
     * @param  array<int, array<int, float>>  $samples
     * @return array<int, float>
     */
    private function predictAll(TrainedModel $model, array $samples): array
    {
        return array_map(
            static fn (array $row): float => max($model->predict(array_combine($model->featureNames, $row)), 0.0),
            $samples,
        );
    }

    /**
     * @param  array<int, float>  $actual
     * @param  array<int, float>  $predicted
     */
    private function intervalCoverage(array $actual, array $predicted, float $residualStd): ?float
    {
        $margin = TrainedModel::INTERVAL_Z * $residualStd;

        return ModelEvaluator::intervalCoverage(
            $actual,
            array_map(static fn (float $p): float => max($p - $margin, 0.0), $predicted),
            array_map(static fn (float $p): float => $p + $margin, $predicted),
        );
    }

    /**
     * @param  array<int, array{lambda: float, mae: float, model: TrainedModel}>  $sweep
     */
    private function reportSweep(array $sweep): void
    {
        $this->table(
            ['lambda', 'holdout MAE'],
            array_map(
                static fn (array $row): array => [$row['lambda'], number_format($row['mae'], 3)],
                $sweep,
            ),
        );
    }

    /**
     * @param  array<string, float|int|null>  $metrics
     * @param  array<string, float|int|null>  $baseline
     */
    private function reportMetrics(array $metrics, array $baseline, ?float $coverage, int $rows, int $holdoutRows): void
    {
        $improvement = $this->improvementPercent($metrics['mae'], $baseline['mae']);

        $this->table(['metric', 'model', 'baseline'], [
            ['MAE', $metrics['mae'] ?? '—', $baseline['mae'] ?? '—'],
            ['RMSE', $metrics['rmse'] ?? '—', $baseline['rmse'] ?? '—'],
            ['R2', $metrics['r2'] ?? '—', $baseline['r2'] ?? '—'],
            ['MAPE %', $metrics['mape'] ?? '—', $baseline['mape'] ?? '—'],
        ]);

        $this->line("  Rows: {$rows} total, {$holdoutRows} held out chronologically.");
        $this->line('  p10-p90 coverage: '.($coverage === null ? '—' : $coverage.'% (80% is the target)'));

        if ($improvement === null) {
            return;
        }

        $improvement >= 0
            ? $this->info("  MAE is {$improvement}% better than the heuristic baseline.")
            : $this->warn('  MAE is '.abs($improvement).'% WORSE than the heuristic baseline.');
    }

    private function reportImportances(TrainedModel $model): void
    {
        $this->line('  Top standardized coefficients:');

        foreach (array_slice($model->featureImportances(), 0, 5) as $importance) {
            $this->line("    {$importance['feature']}: {$importance['coefficient']}");
        }
    }

    /**
     * Store the new version, and promote it only if it is at least as
     * accurate on the holdout as the model currently serving.
     *
     * A weekly retrain on a thin or unusual window can easily fit worse than
     * what is already deployed. Storing every version but gating promotion
     * on measured holdout MAE means a bad training run costs nothing, and
     * the reason it was rejected is printed rather than silently swallowed.
     *
     * @param  array<string, float|int|null>  $metrics
     * @param  array<string, float|int|null>  $baselineMetrics
     * @param  array<int, array{lambda: float, mae: float, model: TrainedModel}>  $sweep
     */
    private function store(
        string $key,
        TrainedModel $model,
        array $metrics,
        array $baselineMetrics,
        ?float $coverage,
        float $residualStd,
        array $sweep,
        int $rows,
        int $holdoutRows,
    ): void {
        $active = MlModel::active($key);
        $activeMae = $active?->metrics['mae'] ?? null;
        $newMae = $metrics['mae'];

        $promote = $activeMae === null || ($newMae !== null && $newMae <= $activeMae);

        DB::transaction(function () use ($key, $model, $metrics, $baselineMetrics, $coverage, $residualStd, $sweep, $rows, $holdoutRows, $promote): void {
            $version = (int) MlModel::where('key', $key)->max('version') + 1;

            if ($promote) {
                MlModel::where('key', $key)->update(['is_active' => false]);
            }

            MlModel::create([
                'key' => $key,
                'version' => $version,
                'algorithm' => 'ridge_regression',
                'feature_names' => $model->featureNames,
                'coefficients' => $model->coefficients,
                'intercept' => $model->intercept,
                'feature_means' => $model->featureMeans,
                'feature_stds' => $model->featureStds,
                'lambda' => $model->lambda,
                'training_rows' => $rows,
                'holdout_rows' => $holdoutRows,
                'metrics' => array_merge($metrics, [
                    'baseline_mae' => $baselineMetrics['mae'],
                    'baseline_rmse' => $baselineMetrics['rmse'],
                    'baseline_r2' => $baselineMetrics['r2'],
                    'baseline_mape' => $baselineMetrics['mape'],
                    'improvement_percent' => $this->improvementPercent($metrics['mae'], $baselineMetrics['mae']),
                    'interval_coverage_percent' => $coverage,
                    'lambda_sweep' => array_map(
                        static fn (array $row): array => ['lambda' => $row['lambda'], 'mae' => round($row['mae'], 3)],
                        $sweep,
                    ),
                ]),
                'residual_std' => $residualStd,
                'is_active' => $promote,
                'trained_at' => now(),
            ]);

            $this->line("  Stored version {$version}.");
        });

        WaitTimePredictor::forgetCachedModel();
        VolumePredictor::forgetCachedModel();

        $promote
            ? $this->info("  Activated: holdout MAE {$newMae}".($activeMae === null ? ' (first model for this key).' : " vs {$activeMae} previously."))
            : $this->warn("  NOT activated: holdout MAE {$newMae} is worse than the active model's {$activeMae}. Keeping version {$active?->version}.");
    }

    /**
     * How much lower the model's error is than the baseline's, as a
     * percentage. Positive means the model is better.
     */
    private function improvementPercent(?float $modelMae, ?float $baselineMae): ?float
    {
        if ($modelMae === null || $baselineMae === null || $baselineMae <= 0.0) {
            return null;
        }

        return round(100 * ($baselineMae - $modelMae) / $baselineMae, 1);
    }
}
