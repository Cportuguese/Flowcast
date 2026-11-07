<?php

namespace App\Services\Ml;

use App\Models\Appointment;
use App\Models\ClinicSchedule;
use App\Models\EventDay;
use App\Models\MlModel;
use App\Models\QueueEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Serves the patient-facing wait estimate.
 *
 * Prefers the trained wait-time model, and falls back to the original
 * people-ahead x mean-consult heuristic whenever no model is active or the
 * one that is was fitted on too little history to trust. The heuristic is
 * kept rather than deleted for two reasons: it is the cold-start path for a
 * clinic with no history yet, and it is the published baseline the model's
 * accuracy is reported against.
 */
class WaitTimePredictor
{
    /**
     * Below this many training rows a fitted model is treated as untrusted
     * and the heuristic serves instead. Roughly a week of clinic arrivals.
     */
    public const MIN_TRAINING_ROWS = 200;

    /** Cache key for the active model, cleared by `ml:train`. */
    public const CACHE_KEY = 'ml.active.wait_time';

    private const CACHE_TTL_SECONDS = 600;

    /**
     * Per-request memo so a single board broadcast, which estimates a wait
     * for every waiting patient, reads each day's entries once.
     *
     * @var array<string, Collection<int, QueueEntry>>
     */
    private array $entriesByDate = [];

    /** @var Collection<int, ClinicSchedule>|null */
    private ?Collection $schedules = null;

    /**
     * Booked times of the nurse's confirmed bookings, per day, memoised
     * like the entries so a board broadcast queries them once.
     *
     * @var array<string, array<int, int>>
     */
    private array $bookingsByDate = [];

    /**
     * Estimate the wait for someone arriving now (or at $at) with
     * $peopleAhead in front of them.
     *
     * @return array{minutes: int, low: int, high: int, source: string, model_version: int|null}
     */
    public function predict(
        Carbon $date,
        int $peopleAhead,
        bool $isAppointment = false,
        ?Carbon $at = null,
    ): array {
        $model = $this->activeModel();

        if (! $model) {
            return $this->heuristicResult($date, $peopleAhead);
        }

        $trained = TrainedModel::fromModel($model);
        $arrivedAt = $at ?? Carbon::now();
        $entries = $this->entriesFor($date);
        [$minutesSinceOpen] = $this->scheduleOffsets($arrivedAt);
        $inConsultation = $entries->where('status', 'in_consultation')->count();

        // The same rule training uses: nobody ahead and the nurse free means
        // being seen at once, which needs no model.
        if (WaitTimeFeatures::isWalkStraightIn($peopleAhead, $inConsultation)) {
            return ['minutes' => 0, 'low' => 0, 'high' => 0, 'source' => 'model', 'model_version' => $model->version];
        }

        $features = WaitTimeFeatures::build(
            arrivedAt: $arrivedAt,
            peopleAhead: $peopleAhead,
            inConsultation: $inConsultation,
            rollingConsultMean: $this->rollingConsultMean($entries),
            bookingsDueNextHour: WaitTimeFeatures::countDueWithin($this->bookingsOn($arrivedAt), $arrivedAt),
            minutesSinceOpen: $minutesSinceOpen,
            isEventDay: EventDay::whereDate('date', $date)->exists(),
            isAppointment: $isAppointment,
        );

        $interval = $trained->predictWithInterval($features);

        return [
            'minutes' => (int) round($interval['point']),
            'low' => (int) round($interval['low']),
            'high' => (int) round($interval['high']),
            'source' => 'model',
            'model_version' => $model->version,
        ];
    }

    /**
     * The original heuristic, unchanged in behaviour: today's mean consult
     * time times the number of people ahead, with a flat five-minute
     * assumption before any consultation has finished.
     */
    public function heuristicMinutes(Carbon $date, int $peopleAhead): int
    {
        if ($peopleAhead === 0) {
            return 0;
        }

        $completed = $this->entriesFor($date)
            ->filter(fn (QueueEntry $entry): bool => $entry->status === 'done'
                && $entry->called_at !== null
                && $entry->completed_at !== null);

        $avgMinutes = $completed->isEmpty()
            ? WaitTimeFeatures::DEFAULT_CONSULT_MINUTES
            : $completed->avg(fn (QueueEntry $entry): float => (float) $entry->called_at->diffInMinutes($entry->completed_at));

        return (int) round($peopleAhead * max($avgMinutes, 1));
    }

    /**
     * The active model, or null when none has been trained or the newest one
     * was fitted on too few rows to serve.
     */
    public function activeModel(): ?MlModel
    {
        $cached = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $model = MlModel::active(MlModel::KEY_WAIT_TIME);

            return ['id' => $model?->id];
        });

        if ($cached['id'] === null) {
            return null;
        }

        $model = MlModel::find($cached['id']);

        if (! $model || ! $model->is_active || $model->training_rows < self::MIN_TRAINING_ROWS) {
            return null;
        }

        // A model fitted on a different feature list (one trained before an
        // upgrade changed the features) cannot be fed today's inputs; the
        // heuristic serves until `ml:train` fits a new one.
        if ($model->feature_names !== WaitTimeFeatures::FEATURE_NAMES) {
            return null;
        }

        return $model;
    }

    /**
     * Drop the per-request queue memo.
     *
     * The predictor reads each day's entries once so a board broadcast does
     * not re-query for every waiting patient. Anything that changes the
     * queue within the same request must clear that memo, or the estimates
     * it then produces describe the queue as it was a moment ago.
     */
    public function refresh(): void
    {
        $this->entriesByDate = [];
        $this->bookingsByDate = [];
    }

    /** Drop the cached active model so the next prediction re-reads it. */
    public static function forgetCachedModel(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The heuristic wrapped in the same shape as a model prediction. The
     * band is a flat proportional spread rather than a residual quantile,
     * because a hand-written formula has no measured error distribution to
     * draw one from — and saying so is more honest than implying it does.
     *
     * @return array{minutes: int, low: int, high: int, source: string, model_version: int|null}
     */
    private function heuristicResult(Carbon $date, int $peopleAhead): array
    {
        $minutes = $this->heuristicMinutes($date, $peopleAhead);

        return [
            'minutes' => $minutes,
            'low' => (int) round($minutes * 0.6),
            'high' => (int) round($minutes * 1.5),
            'source' => 'heuristic',
            'model_version' => null,
        ];
    }

    /**
     * The nurse's bookings still to be seen on $moment's day. Only
     * confirmed ones count: a booking under review may never happen, and
     * one already finished or missed is no longer ahead of anyone.
     *
     * @return array<int, int> booked times as timestamps
     */
    private function bookingsOn(Carbon $moment): array
    {
        return $this->bookingsByDate[$moment->toDateString()] ??= Appointment::query()
            ->throughQueue()
            ->whereIn('status', Appointment::OPEN_STATUSES)
            ->whereDate('scheduled_at', $moment)
            ->pluck('scheduled_at')
            ->map(fn ($at): int => Carbon::parse($at)->getTimestamp())
            ->all();
    }

    /**
     * @param  Collection<int, QueueEntry>  $entries
     */
    private function rollingConsultMean(Collection $entries): float
    {
        $durations = $entries
            ->filter(fn (QueueEntry $entry): bool => $entry->called_at !== null && $entry->completed_at !== null)
            ->sortBy(fn (QueueEntry $entry): string => (string) $entry->completed_at)
            ->slice(-WaitTimeFeatures::ROLLING_WINDOW)
            ->map(fn (QueueEntry $entry): float => (float) $entry->called_at->diffInMinutes($entry->completed_at));

        return $durations->isEmpty()
            ? WaitTimeFeatures::DEFAULT_CONSULT_MINUTES
            : max((float) $durations->avg(), 1.0);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function scheduleOffsets(Carbon $moment): array
    {
        return WaitTimeFeatures::scheduleOffsets($moment, $this->schedules ??= WaitTimeFeatures::schedulesByWeekday());
    }

    /**
     * @return Collection<int, QueueEntry>
     */
    private function entriesFor(Carbon $date): Collection
    {
        // Matched on the Carbon instance, not a date string: `queue_date`
        // is cast to a date and round-trips with a midnight time, so an
        // exact string comparison silently finds nothing.
        return $this->entriesByDate[$date->toDateString()] ??= QueueEntry::where('queue_date', $date)->get();
    }
}
