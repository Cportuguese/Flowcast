<?php

namespace App\Services\Ml;

use App\Models\ClinicSchedule;
use App\Models\EventDay;
use App\Models\QueueEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Feature extraction for the per-arrival wait-time model.
 *
 * `build()` is the ONLY place a feature vector is produced. Training
 * reconstructs historical inputs and calls it; live prediction measures the
 * current queue and calls the same method with the same argument list. When
 * training and serving each grow their own extraction code the two drift
 * apart silently and the deployed model quietly stops matching the one that
 * was evaluated, so that path is closed off here by construction.
 *
 * Every feature is computed strictly as of the moment of arrival. The one
 * feature that reads the whole day, `active_staff`, is the day's personnel
 * roster, which is known when the clinic opens rather than discovered later.
 */
class WaitTimeFeatures
{
    /**
     * Canonical feature order. Coefficients are stored positionally against
     * this list, so appending is safe but reordering invalidates every model
     * trained before the change.
     *
     * @var array<int, string>
     */
    public const FEATURE_NAMES = [
        'people_ahead',
        'in_consultation',
        'active_staff',
        'people_ahead_per_staff',
        'rolling_consult_mean',
        'hour_sin',
        'hour_cos',
        'minutes_since_open',
        'minutes_until_close',
        'is_event_day',
        'is_appointment',
        'dow_1',
        'dow_2',
        'dow_3',
        'dow_4',
        'dow_5',
        'dow_6',
    ];

    /** How many recent consultations feed the rolling mean. */
    public const ROLLING_WINDOW = 10;

    /** Consult length assumed before any consultation has finished. */
    public const DEFAULT_CONSULT_MINUTES = 5.0;

    /** Clinic hours assumed when a weekday has no configured schedule. */
    private const DEFAULT_OPEN_HOUR = 8;

    private const DEFAULT_CLOSE_HOUR = 17;

    /**
     * The single extraction path shared by training and inference.
     *
     * @return array<string, float> keyed by feature name, in FEATURE_NAMES order
     */
    public static function build(
        Carbon $arrivedAt,
        int $peopleAhead,
        int $inConsultation,
        int $activeStaff,
        float $rollingConsultMean,
        int $minutesSinceOpen,
        int $minutesUntilClose,
        bool $isEventDay,
        bool $isAppointment,
    ): array {
        $staff = max($activeStaff, 1);
        $hourOfDay = $arrivedAt->hour + ($arrivedAt->minute / 60);
        $dayOfWeek = $arrivedAt->dayOfWeek;

        $features = [
            'people_ahead' => (float) $peopleAhead,
            'in_consultation' => (float) $inConsultation,
            'active_staff' => (float) $staff,
            'people_ahead_per_staff' => (float) $peopleAhead / $staff,
            'rolling_consult_mean' => $rollingConsultMean,
            'hour_sin' => sin(2 * M_PI * $hourOfDay / 24),
            'hour_cos' => cos(2 * M_PI * $hourOfDay / 24),
            'minutes_since_open' => (float) max($minutesSinceOpen, 0),
            'minutes_until_close' => (float) max($minutesUntilClose, 0),
            'is_event_day' => $isEventDay ? 1.0 : 0.0,
            'is_appointment' => $isAppointment ? 1.0 : 0.0,
        ];

        /** Sunday (0) is the omitted baseline category. */
        for ($day = 1; $day <= 6; $day++) {
            $features["dow_{$day}"] = $dayOfWeek === $day ? 1.0 : 0.0;
        }

        return $features;
    }

    /**
     * Minutes from the clinic's opening, and until its closing, for the
     * weekday the given moment falls on.
     *
     * @param  Collection<int, ClinicSchedule>  $schedules  keyed by day_of_week
     * @return array{0: int, 1: int}
     */
    public static function scheduleOffsets(Carbon $moment, Collection $schedules): array
    {
        $schedule = $schedules->get($moment->dayOfWeek);

        $open = $schedule
            ? $moment->copy()->setTimeFromTimeString($schedule->start_time)
            : $moment->copy()->setTime(self::DEFAULT_OPEN_HOUR, 0);

        $close = $schedule
            ? $moment->copy()->setTimeFromTimeString($schedule->end_time)
            : $moment->copy()->setTime(self::DEFAULT_CLOSE_HOUR, 0);

        return [
            (int) round($open->diffInMinutes($moment, false)),
            (int) round($moment->diffInMinutes($close, false)),
        ];
    }

    /**
     * Active clinic schedules keyed by weekday, for reuse across many
     * extractions without re-querying.
     *
     * @return Collection<int, ClinicSchedule>
     */
    public static function schedulesByWeekday(): Collection
    {
        return ClinicSchedule::where('is_active', true)->get()->keyBy('day_of_week');
    }

    /**
     * Rebuild one training row per called queue entry, in chronological
     * order, by replaying each clinic day arrival by arrival.
     *
     * Replaying the day rather than querying per entry keeps the whole
     * dataset to a handful of queries, and makes the as-of-arrival state
     * explicit enough to audit.
     *
     * @return array{
     *     samples: array<int, array<int, float>>,
     *     targets: array<int, float>,
     *     dates: array<int, string>,
     *     feature_names: array<int, string>
     * }
     */
    public static function trainingSet(): array
    {
        $schedules = self::schedulesByWeekday();
        $eventDates = EventDay::query()->pluck('date')
            ->map(fn (Carbon $date): string => $date->toDateString())
            ->flip();

        $samples = [];
        $targets = [];
        $dates = [];

        $byDate = QueueEntry::query()
            ->whereNotNull('called_at')
            ->orderBy('queue_date')
            ->orderBy('queue_number')
            ->get()
            ->groupBy(fn (QueueEntry $entry): string => Carbon::parse($entry->queue_date)->toDateString());

        foreach ($byDate as $date => $dayEntries) {
            $isEventDay = $eventDates->has($date);
            $activeStaff = $dayEntries->pluck('handled_by_id')->filter()->unique()->count();

            foreach ($dayEntries as $entry) {
                if (! $entry->joined_at || ! $entry->called_at) {
                    continue;
                }

                $arrivedAt = Carbon::parse($entry->joined_at);
                [$sinceOpen, $untilClose] = self::scheduleOffsets($arrivedAt, $schedules);

                $features = self::build(
                    arrivedAt: $arrivedAt,
                    peopleAhead: self::peopleAheadAt($dayEntries, $entry, $arrivedAt),
                    inConsultation: self::inConsultationAt($dayEntries, $arrivedAt),
                    activeStaff: $activeStaff,
                    rollingConsultMean: self::rollingConsultMeanAt($dayEntries, $arrivedAt),
                    minutesSinceOpen: $sinceOpen,
                    minutesUntilClose: $untilClose,
                    isEventDay: $isEventDay,
                    isAppointment: $entry->source === 'appointment',
                );

                $samples[] = array_values($features);
                $targets[] = (float) round($arrivedAt->diffInMinutes(Carbon::parse($entry->called_at), false));
                $dates[] = $date;
            }
        }

        return [
            'samples' => $samples,
            'targets' => $targets,
            'dates' => $dates,
            'feature_names' => self::FEATURE_NAMES,
        ];
    }

    /**
     * Entries that arrived earlier and had not yet been called at the moment
     * this one joined.
     *
     * @param  Collection<int, QueueEntry>  $dayEntries
     */
    private static function peopleAheadAt(Collection $dayEntries, QueueEntry $entry, Carbon $arrivedAt): int
    {
        return $dayEntries->filter(function (QueueEntry $other) use ($entry, $arrivedAt): bool {
            if ($other->queue_number >= $entry->queue_number) {
                return false;
            }

            return $other->called_at === null || Carbon::parse($other->called_at)->greaterThan($arrivedAt);
        })->count();
    }

    /**
     * Entries already called but not yet finished at the given moment.
     *
     * @param  Collection<int, QueueEntry>  $dayEntries
     */
    private static function inConsultationAt(Collection $dayEntries, Carbon $arrivedAt): int
    {
        return $dayEntries->filter(function (QueueEntry $other) use ($arrivedAt): bool {
            if ($other->called_at === null || Carbon::parse($other->called_at)->greaterThan($arrivedAt)) {
                return false;
            }

            return $other->completed_at === null || Carbon::parse($other->completed_at)->greaterThan($arrivedAt);
        })->count();
    }

    /**
     * Mean length of the last ROLLING_WINDOW consultations that had already
     * finished when this patient arrived — the clinic's pace right now,
     * using only information available at that moment.
     *
     * @param  Collection<int, QueueEntry>  $dayEntries
     */
    private static function rollingConsultMeanAt(Collection $dayEntries, Carbon $arrivedAt): float
    {
        $durations = $dayEntries
            ->filter(fn (QueueEntry $other): bool => $other->called_at !== null
                && $other->completed_at !== null
                && Carbon::parse($other->completed_at)->lessThanOrEqualTo($arrivedAt))
            ->sortBy(fn (QueueEntry $other): string => (string) $other->completed_at)
            ->slice(-self::ROLLING_WINDOW)
            ->map(fn (QueueEntry $other): float => (float) Carbon::parse($other->called_at)
                ->diffInMinutes(Carbon::parse($other->completed_at), false));

        return $durations->isEmpty()
            ? self::DEFAULT_CONSULT_MINUTES
            : max((float) $durations->avg(), 1.0);
    }

    /**
     * The pre-existing heuristic — people ahead times the clinic's recent
     * mean consult time — evaluated on the same feature rows the model sees.
     *
     * Scoring the baseline from the identical holdout rows is what makes the
     * comparison fair: both are asked the same question about the same
     * arrivals, so a difference in MAE is a difference in accuracy and not
     * an artefact of different evaluation sets.
     *
     * @param  array<int, array<int, float>>  $samples
     * @return array<int, float>
     */
    public static function baselinePredictions(array $samples): array
    {
        $peopleAhead = (int) array_search('people_ahead', self::FEATURE_NAMES, true);
        $consultMean = (int) array_search('rolling_consult_mean', self::FEATURE_NAMES, true);

        return array_map(
            static fn (array $row): float => $row[$peopleAhead] * max($row[$consultMean], 1.0),
            $samples,
        );
    }
}
