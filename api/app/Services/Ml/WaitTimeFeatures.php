<?php

namespace App\Services\Ml;

use App\Models\Appointment;
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
 * that looks ahead, `bookings_due_next_hour`, reads the nurse's confirmed
 * bookings — which are on her schedule before the patient walks in, so it
 * is known at arrival rather than discovered later.
 *
 * UDD's clinic has one nurse running the queue, so the staffing features of
 * the first version (`active_staff`, `people_ahead_per_staff`) were dropped
 * after the alpha review: with one nurse they never vary and carry nothing.
 *
 * With one nurse the old formula — people ahead x the recent consultation
 * pace — is already most of the answer, so it is now an INPUT
 * (`queue_minutes_ahead`) rather than a rival: the model learns how far to
 * trust it and what to add on top. Chiefly the booked patients the nurse
 * will see at their booked time, ahead of the line, and the patient already
 * in the consultation room.
 *
 * `minutes_until_close` was dropped too: every clinic day now runs 8 to 6,
 * so it was always 600 minus `minutes_since_open`, and ridge split that one
 * signal into two huge opposite coefficients that read as nonsense.
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
        'queue_minutes_ahead',
        'people_ahead',
        'in_consultation',
        'bookings_due_next_hour',
        'hour_sin',
        'hour_cos',
        'minutes_since_open',
        'is_event_day',
        'is_appointment',
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

    /** How far ahead `bookings_due_next_hour` looks, in minutes. */
    public const BOOKING_LOOKAHEAD_MINUTES = 60;

    /**
     * Booking statuses that were on the nurse's schedule at the time: in
     * history a booking that later finished or was missed was still due
     * when an earlier patient arrived. Cancelled, declined and unreviewed
     * bookings never were.
     */
    public const SCHEDULED_BOOKING_STATUSES = ['booked', 'checked_in', 'completed', 'no_show'];

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
        float $rollingConsultMean,
        int $bookingsDueNextHour,
        int $minutesSinceOpen,
        bool $isEventDay,
        bool $isAppointment,
    ): array {
        $hourOfDay = $arrivedAt->hour + ($arrivedAt->minute / 60);
        $dayOfWeek = $arrivedAt->dayOfWeek;

        $features = [
            'queue_minutes_ahead' => self::queueMinutes($peopleAhead, $rollingConsultMean),
            'people_ahead' => (float) $peopleAhead,
            'in_consultation' => (float) $inConsultation,
            'bookings_due_next_hour' => (float) $bookingsDueNextHour,
            'hour_sin' => sin(2 * M_PI * $hourOfDay / 24),
            'hour_cos' => cos(2 * M_PI * $hourOfDay / 24),
            'minutes_since_open' => (float) max($minutesSinceOpen, 0),
            'is_event_day' => $isEventDay ? 1.0 : 0.0,
            'is_appointment' => $isAppointment ? 1.0 : 0.0,
        ];

        /*
         * Monday is the omitted baseline. The clinic never opens on Sunday,
         * so with Sunday as the baseline the six dummies always summed to one
         * and duplicated the intercept: ridge then smeared a constant across
         * them and the importance chart showed big weekday bars that meant
         * nothing. A Sunday arrival (never in training) reads as a Monday.
         */
        for ($day = 2; $day <= 6; $day++) {
            $features["dow_{$day}"] = $dayOfWeek === $day ? 1.0 : 0.0;
        }

        return $features;
    }

    /**
     * Nobody waiting ahead and nobody with the nurse: the patient is called
     * straight away, so the wait is zero by definition rather than by
     * prediction. Training, scoring and serving all treat this case the
     * same way.
     */
    public static function isWalkStraightIn(int $peopleAhead, int $inConsultation): bool
    {
        return $peopleAhead === 0 && $inConsultation === 0;
    }

    /**
     * The old formula's answer: everyone ahead, at the clinic's recent pace.
     * Never below one minute a head, as the formula has always assumed.
     */
    public static function queueMinutes(int $peopleAhead, float $rollingConsultMean): float
    {
        return (float) $peopleAhead * max($rollingConsultMean, 1.0);
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

        $bookingsByDate = self::queueBookingTimesByDate();

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
            $dayBookings = $bookingsByDate[$date] ?? [];

            foreach ($dayEntries as $entry) {
                if (! $entry->joined_at || ! $entry->called_at) {
                    continue;
                }

                $arrivedAt = Carbon::parse($entry->joined_at);
                [$sinceOpen] = self::scheduleOffsets($arrivedAt, $schedules);
                $peopleAhead = self::peopleAheadAt($dayEntries, $entry, $arrivedAt);
                $inConsultation = self::inConsultationAt($dayEntries, $entry, $arrivedAt);

                // Nobody ahead and the nurse free: seen at once, by
                // definition. There is nothing to predict, so these rows
                // neither train nor score the model (or the baseline).
                if (self::isWalkStraightIn($peopleAhead, $inConsultation)) {
                    continue;
                }

                $features = self::build(
                    arrivedAt: $arrivedAt,
                    peopleAhead: $peopleAhead,
                    inConsultation: $inConsultation,
                    rollingConsultMean: self::rollingConsultMeanAt($dayEntries, $arrivedAt),
                    bookingsDueNextHour: self::countDueWithin($dayBookings, $arrivedAt),
                    minutesSinceOpen: $sinceOpen,
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
     * The nurse's confirmed bookings due after $moment and within the
     * look-ahead window — the patients she will take ahead of the line soon.
     *
     * @param  array<int, int>  $bookingTimestamps  that day's booked times
     */
    public static function countDueWithin(array $bookingTimestamps, Carbon $moment): int
    {
        $from = $moment->getTimestamp();
        $until = $from + self::BOOKING_LOOKAHEAD_MINUTES * 60;

        return count(array_filter(
            $bookingTimestamps,
            static fn (int $at): bool => $at > $from && $at <= $until,
        ));
    }

    /**
     * Booked times of every scheduled booking that goes through the queue
     * (the nurse's), grouped by date — one query for the whole history.
     *
     * @return array<string, array<int, int>> date string => timestamps
     */
    public static function queueBookingTimesByDate(): array
    {
        return Appointment::query()
            ->throughQueue()
            ->whereIn('status', self::SCHEDULED_BOOKING_STATUSES)
            ->get(['scheduled_at'])
            ->groupBy(fn (Appointment $booking): string => $booking->scheduled_at->toDateString())
            ->map(fn ($bookings): array => $bookings->map(fn (Appointment $booking): int => $booking->scheduled_at->getTimestamp())->values()->all())
            ->all();
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
     * Other patients already called, and not yet finished, when this one
     * arrived.
     *
     * Never the arriving patient: someone called the second they walk in has
     * a called_at equal to their own arrival, and counting them as "in
     * consultation" would leak their zero wait into their own features —
     * the live queue never sees that, so it was training/serving skew.
     *
     * @param  Collection<int, QueueEntry>  $dayEntries
     */
    public static function inConsultationAt(Collection $dayEntries, QueueEntry $entry, Carbon $arrivedAt): int
    {
        return $dayEntries->filter(function (QueueEntry $other) use ($entry, $arrivedAt): bool {
            if ($other->id === $entry->id) {
                return false;
            }

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
     * an artefact of different evaluation sets. The formula's answer is the
     * `queue_minutes_ahead` input itself, so the baseline reads it straight
     * off the row.
     *
     * @param  array<int, array<int, float>>  $samples
     * @return array<int, float>
     */
    public static function baselinePredictions(array $samples): array
    {
        $queueMinutes = (int) array_search('queue_minutes_ahead', self::FEATURE_NAMES, true);

        return array_map(static fn (array $row): float => $row[$queueMinutes], $samples);
    }
}
