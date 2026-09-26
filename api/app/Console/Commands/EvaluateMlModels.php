<?php

namespace App\Console\Commands;

use App\Models\MlModel;
use App\Models\QueueEntry;
use App\Services\Ml\TrainedModel;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('ml:evaluate {key? : wait_time or volume; both when omitted}')]
#[Description('Compare each active model against the heuristic baseline it replaced.')]
class EvaluateMlModels extends Command
{
    /** How far back the online predicted-vs-actual check looks. */
    private const ONLINE_WINDOW_DAYS = 30;

    public function handle(): int
    {
        $keys = $this->argument('key')
            ? [$this->argument('key')]
            : [MlModel::KEY_WAIT_TIME, MlModel::KEY_VOLUME];

        foreach ($keys as $key) {
            if (! in_array($key, [MlModel::KEY_WAIT_TIME, MlModel::KEY_VOLUME], true)) {
                $this->error("Unknown model key [{$key}].");

                return self::FAILURE;
            }

            $this->newLine();
            $this->evaluateKey($key);
        }

        return self::SUCCESS;
    }

    /**
     * Report the metrics recorded when the active version was fitted.
     *
     * These come from the train-only fit scored on the held-out tail, and
     * are deliberately NOT recomputed here. The serving model was refit on
     * all history at the chosen penalty, so scoring it against the holdout
     * now would be scoring it on rows it has since been trained on — which
     * quietly flatters every figure. The stored numbers are the honest
     * out-of-sample ones.
     */
    private function evaluateKey(string $key): void
    {
        $active = MlModel::active($key);

        if (! $active) {
            $this->warn("[{$key}] No active model — the heuristic is serving. Run `php artisan ml:train {$key}` once there is history.");

            return;
        }

        $metrics = $active->metrics ?? [];
        $unit = $key === MlModel::KEY_WAIT_TIME ? 'minutes' : 'arrivals';

        $this->info("[{$key}] version {$active->version}, lambda {$active->lambda}, trained {$active->trained_at->diffForHumans()}");
        $this->line("  Fitted on {$active->training_rows} row(s); {$active->holdout_rows} held out as the most recent days.");

        $this->table(
            ['metric', 'heuristic baseline', 'ridge model', 'change'],
            [
                $this->comparisonRow("MAE ({$unit})", $metrics['baseline_mae'] ?? null, $metrics['mae'] ?? null, lowerIsBetter: true),
                $this->comparisonRow("RMSE ({$unit})", $metrics['baseline_rmse'] ?? null, $metrics['rmse'] ?? null, lowerIsBetter: true),
                $this->comparisonRow('R2', $metrics['baseline_r2'] ?? null, $metrics['r2'] ?? null, lowerIsBetter: false),
                $this->comparisonRow('MAPE %', $metrics['baseline_mape'] ?? null, $metrics['mape'] ?? null, lowerIsBetter: true),
            ],
        );

        $coverage = $metrics['interval_coverage_percent'] ?? null;
        $this->line('  p10-p90 interval coverage: '.($coverage === null ? '—' : "{$coverage}% (80% is the target)"));

        $this->reportSweep($metrics['lambda_sweep'] ?? []);
        $this->reportImportances(TrainedModel::fromModel($active));

        if ($key === MlModel::KEY_WAIT_TIME) {
            $this->reportOnlineAccuracy();
        }
    }

    /**
     * @param  array<int, array{lambda: float|int, mae: float}>  $sweep
     */
    private function reportSweep(array $sweep): void
    {
        if ($sweep === []) {
            return;
        }

        $this->line('  Ridge penalty sweep against the same holdout:');
        $this->table(
            ['lambda', 'holdout MAE'],
            array_map(
                static fn (array $row): array => [$row['lambda'], $row['mae']],
                $sweep,
            ),
        );
    }

    /**
     * @return array<int, string>
     */
    private function comparisonRow(string $label, ?float $baseline, ?float $model, bool $lowerIsBetter): array
    {
        if ($baseline === null || $model === null) {
            return [$label, $baseline ?? '—', $model ?? '—', '—'];
        }

        $delta = $lowerIsBetter ? $baseline - $model : $model - $baseline;
        $scale = abs($baseline) > 1e-9 ? abs($baseline) : 1.0;
        $percent = round(100 * $delta / $scale, 1);

        return [
            $label,
            (string) $baseline,
            (string) $model,
            ($delta >= 0 ? 'better by ' : 'worse by ').abs($percent).'%',
        ];
    }

    private function reportImportances(TrainedModel $model): void
    {
        $this->newLine();
        $this->line('  Standardized coefficients (comparable across features):');

        $this->table(
            ['feature', 'coefficient'],
            array_map(
                static fn (array $row): array => [$row['feature'], $row['coefficient']],
                array_slice($model->featureImportances(), 0, 8),
            ),
        );
    }

    /**
     * Accuracy of the estimates actually shown to patients, measured from
     * the prediction stored on each queue entry at arrival.
     *
     * This is the number that matters most: a holdout is a reconstruction,
     * but these are the figures real people were given and the waits they
     * really had.
     */
    private function reportOnlineAccuracy(): void
    {
        $entries = QueueEntry::query()
            ->whereNotNull('predicted_wait_minutes')
            ->whereNotNull('called_at')
            ->where('queue_date', '>=', Carbon::today()->subDays(self::ONLINE_WINDOW_DAYS)->toDateString())
            ->get();

        if ($entries->isEmpty()) {
            $this->line('  Online predicted-vs-actual: no recorded predictions yet.');

            return;
        }

        $actual = $entries->map(fn (QueueEntry $entry): float => (float) $entry->joined_at->diffInMinutes($entry->called_at))->all();
        $predicted = $entries->map(fn (QueueEntry $entry): float => (float) $entry->predicted_wait_minutes)->all();
        $metrics = ModelEvaluator::metrics($actual, $predicted);

        $this->line('  Online predicted-vs-actual over the last '.self::ONLINE_WINDOW_DAYS." days: MAE {$metrics['mae']} min across {$metrics['n']} live arrival(s).");
    }
}
