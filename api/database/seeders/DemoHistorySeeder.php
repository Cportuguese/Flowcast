<?php

namespace Database\Seeders;

use App\Models\EventDay;
use App\Models\User;
use Database\Seeders\Concerns\SamplesClinicalCases;
use Database\Seeders\Concerns\SamplesPatients;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * SYNTHETIC DATA. Nothing this seeder writes is a real patient, a real
 * visit or a real measurement. It exists because the models need history to
 * fit and this repository ships with none; any figure derived from it must
 * be described as simulated wherever it is presented.
 *
 * It simulates UDD's clinic as it actually runs: ONE nurse working the
 * walk-in queue Monday to Saturday, 8 AM to 6 PM, a dentist seeing booked
 * patients on Tuesdays and Thursdays and a school physician on Friday
 * afternoons. The process is deliberately KNOWN, so the trained model can
 * be checked against ground truth rather than merely admired:
 *
 * - per-weekday base walk-in rates (WEEKDAY_BASE_ARRIVALS), quieter Saturdays
 * - a midday dip in arrivals (HOURLY_SHAPE)
 * - a lift of EVENT_DAY_MULTIPLIER on flagged days: every clinic day of an
 *   exam week, plus an occasional one-off campus event. Deliberately NOT the
 *   1.5 the old heuristic hardcoded, so it is visible whether the model
 *   learned the real number or inherited the guess
 * - Poisson arrivals within each hour, lognormal consultation lengths
 * - patients booked with the nurse are called at their booked time, ahead
 *   of the walk-ins, if they arrive on time (as the live queue does)
 * - a single-server service simulation, so joined_at, called_at and
 *   completed_at are mutually consistent rather than random
 *
 * Because booked patients cut in at their time, a walk-in's wait depends on
 * how many bookings fall due soon after they arrive — exactly what
 * `bookings_due_next_hour` is there to capture and the old people-ahead x
 * consult-time heuristic cannot see.
 *
 * Re-running wipes and regenerates the demo history; it is not additive.
 */
class DemoHistorySeeder extends Seeder
{
    use SamplesClinicalCases;
    use SamplesPatients;

    /** How much history to generate. */
    public const DAYS_OF_HISTORY = 182;

    /** Overridable so a test can simulate a few weeks instead of six months. */
    public int $days = self::DAYS_OF_HISTORY;

    /** Overridable: a history of years needs far more than the alpha's 240 patients. */
    public ?int $patientCount = null;

    /**
     * Over years a patient can only turn up while enrolled: a student whose
     * ID says they entered in 2025 has no visits in 2023. Off by default so
     * the six-month alpha history stays exactly as it was.
     */
    public bool $enrollmentAware = false;

    /** Fixed so a rerun reproduces the same history for the same write-up. */
    private const RANDOM_SEED = 20261012;

    private const PATIENT_COUNT = 240;

    /** Students are treated as enrolled for this many years after entry. */
    private const YEARS_ENROLLED = 6;

    private Carbon $historyStart;

    /**
     * Patient ids enrolled in each month (year * 12 + month), when
     * $enrollmentAware is on.
     *
     * @var array<int, array<int, int>>
     */
    private array $enrolledByMonth = [];

    /** Marks event days this seeder owns, so a rerun can clear its own. */
    private const SYNTHETIC_NOTE = 'Synthetic demo data.';

    /**
     * Mean walk-ins on an ordinary day, by day of week (0 = Sunday, closed).
     * One nurse with ~9-minute consultations can see about 65 people in a
     * ten-hour day, so ordinary days run at a realistic 50-70% load and
     * event days push close to the limit.
     */
    private const WEEKDAY_BASE_ARRIVALS = [1 => 44, 2 => 38, 3 => 36, 4 => 38, 5 => 34, 6 => 16];

    /** Share of a day's walk-ins arriving in each opening hour. */
    private const HOURLY_SHAPE = [
        8 => 0.12, 9 => 0.14, 10 => 0.13, 11 => 0.11, 12 => 0.05,
        13 => 0.10, 14 => 0.11, 15 => 0.10, 16 => 0.08, 17 => 0.06,
    ];

    /**
     * The true lift on a flagged day. Deliberately not the 1.5 the old
     * heuristic hardcoded, so it is visible whether the model learned the
     * real number or simply inherited the guess.
     */
    private const EVENT_DAY_MULTIPLIER = 1.45;

    /** Lognormal consultation length: median e^LOG_CONSULT_MU minutes. */
    private const LOG_CONSULT_MU = 2.1;

    private const LOG_CONSULT_SIGMA = 0.45;

    private const MIN_CONSULT_MINUTES = 3;

    private const MAX_CONSULT_MINUTES = 30;

    /** Arrivals still uncalled this long after closing are marked skipped. */
    private const GRACE_MINUTES_AFTER_CLOSE = 30;

    /** Share of called patients who had already left (skipped). */
    private const NOT_THERE_SHARE = 0.03;

    /** Mean confirmed-or-not bookings per open day, per professional. */
    private const BOOKINGS_PER_DAY = ['nurse' => 3.0, 'dentist' => 7.0, 'physician' => 4.0];

    /**
     * What happens to a booking. The nurse's on-time patients go through
     * her queue; late ones (past the 15-minute grace) wait like walk-ins.
     *
     * @var array<string, float> outcome => share
     */
    private const NURSE_BOOKING_OUTCOMES = ['declined' => 0.03, 'cancelled' => 0.05, 'no_show' => 0.08, 'late' => 0.08, 'on_time' => 0.76];

    /** @var array<string, float> */
    private const BOOKED_DAY_OUTCOMES = ['declined' => 0.02, 'cancelled' => 0.05, 'no_show' => 0.09, 'completed' => 0.84];

    /** How late counts as late — the same grace the live queue gives. */
    private const LATE_GRACE_MINUTES = 15;

    /** Share of completed queue consultations that produced a visit record. */
    private const VISIT_RECORD_SHARE = 0.9;

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoHistorySeeder generates synthetic patients and visits. Refusing to run in production.');

            return;
        }

        $this->command?->warn('Generating SYNTHETIC clinic history — not real patient data.');

        $providers = $this->ensureClinic();

        $this->historyStart = Carbon::today()->subDays($this->days);

        // Patients get their own seed so the simulation below draws the same
        // numbers whether or not the patients already existed.
        mt_srand(self::RANDOM_SEED + 1);
        $patients = $this->ensurePatients();

        if ($this->enrollmentAware) {
            $this->enrolledByMonth = $this->enrollmentByMonth($patients);
        }

        mt_srand(self::RANDOM_SEED);
        $start = $this->historyStart->copy();

        $this->purgePreviousHistory($start);

        $eventDates = $this->seedEventDays($start);

        $totals = $this->simulate($start, $patients, $providers, $eventDates);

        $this->command?->info("Generated {$totals['entries']} queue entries and {$totals['bookings']} bookings across {$totals['days']} clinic day(s).");

        $this->snapshot($start);
    }

    /**
     * The three professionals and the clinic's hours. A fresh database gets
     * the dev accounts; one that has them only gets any missing hours.
     *
     * @return array{nurse: int, dentist: int, physician: int}
     */
    private function ensureClinic(): array
    {
        $missing = fn (): bool => User::providers()->distinct()->count('profession') < 3;

        if ($missing()) {
            $this->call(DevUsersSeeder::class);
        } else {
            $this->call(ClinicSetupSeeder::class);
        }

        return [
            'nurse' => (int) User::providers()->where('profession', 'nurse')->value('id'),
            'dentist' => (int) User::providers()->where('profession', 'dentist')->value('id'),
            'physician' => (int) User::providers()->where('profession', 'physician')->value('id'),
        ];
    }

    /**
     * @return array<int, int> patient user ids
     */
    private function ensurePatients(): array
    {
        $existing = User::where('email', 'like', 'demo.patient%@flowcast.test')->pluck('id', 'email')->all();
        $this->usedIdNumbers = User::whereNotNull('school_id')->pluck('school_id')->flip()->map(fn () => true)->all();

        $password = Hash::make('password');
        $now = now();
        $new = [];
        // Returning patients: the nurse checked each one's UDD ID card on
        // their first visit.
        $nurseId = User::where('profession', 'nurse')->value('id');

        for ($i = 1; $i <= ($this->patientCount ?? self::PATIENT_COUNT); $i++) {
            $email = "demo.patient{$i}@flowcast.test";
            $row = $this->samplePatientRow($i, $email);

            if (isset($existing[$email])) {
                continue;
            }

            // Over a long history the account is as old as the patient's
            // time at the clinic, not all created on the day of seeding.
            $registered = $this->enrollmentAware ? $this->registeredAt($row) : $now;

            $new[] = $row + [
                'password' => $password,
                'email_verified_at' => $registered,
                'school_id_verified_at' => $registered,
                'school_id_verified_by_id' => $nurseId,
                'created_at' => $registered,
                'updated_at' => $registered,
            ];
        }

        foreach (array_chunk($new, 200) as $chunk) {
            DB::table('users')->insert($chunk);
        }

        return User::where('email', 'like', 'demo.patient%@flowcast.test')->pluck('id')->all();
    }

    /**
     * The year a student entered, from the first two digits of their UDD ID
     * number (23-1057-651). Null for faculty and staff, who have no entry
     * year in their ID number and are always enrolled — and for the graduate
     * students whose ID is too old to still be an undergraduate's (a master's
     * student's ID can date from long before the history begins).
     *
     * @param  array<string, mixed>  $patient  a users row: school_id, patient_type
     */
    private function entryYear(array $patient): ?int
    {
        if (($patient['patient_type'] ?? null) !== 'student' || ! preg_match('/^(\d{2})-\d{4}-\d{3}$/', (string) ($patient['school_id'] ?? ''), $parts)) {
            return null;
        }

        $entry = 2000 + (int) $parts[1];

        return $entry + self::YEARS_ENROLLED < $this->historyStart->year ? null : $entry;
    }

    /**
     * When the patient's account was made: a student at the start of the
     * school year they entered (June). Anyone already at the clinic when the
     * history begins — faculty and staff, students who entered earlier —
     * registered in the weeks before it, when the system went live.
     *
     * @param  array<string, mixed>  $patient
     */
    private function registeredAt(array $patient): string
    {
        $entry = $this->entryYear($patient);
        $goLive = $this->historyStart->copy()->subDays(mt_rand(1, 60));

        $date = $entry === null
            ? $goLive
            : Carbon::create($entry, 6, 1)->addDays(mt_rand(0, 29))->max($goLive);

        return $date->setTime(mt_rand(8, 17), mt_rand(0, 59))->toDateTimeString();
    }

    /**
     * Which patients can have a visit in each month of the history: students
     * from the July after their entry year for YEARS_ENROLLED years, faculty
     * and staff throughout.
     *
     * @param  array<int, int>  $patientIds
     * @return array<int, array<int, int>> (year * 12 + month) => patient ids
     */
    private function enrollmentByMonth(array $patientIds): array
    {
        $patients = User::whereIn('id', $patientIds)->get(['id', 'school_id', 'patient_type'])->all();
        $byMonth = [];

        for ($cursor = $this->historyStart->copy()->startOfMonth(); $cursor->lessThanOrEqualTo(Carbon::today()); $cursor->addMonth()) {
            $key = $cursor->year * 12 + $cursor->month;

            foreach ($patients as $patient) {
                $entry = $this->entryYear($patient->only(['school_id', 'patient_type']));
                $enrolled = $entry === null
                    || ($key >= $entry * 12 + 7 && $key <= ($entry + self::YEARS_ENROLLED) * 12 + 12);

                if ($enrolled) {
                    $byMonth[$key][] = $patient->id;
                }
            }

            $byMonth[$key] ??= $patientIds;
        }

        return $byMonth;
    }

    /**
     * A random patient for a visit on $date. With enrollment on, only one
     * enrolled that month; otherwise any (one draw either way, so the
     * alpha history is reproduced exactly).
     *
     * @param  array<int, int>  $patients
     */
    private function patientFor(Carbon $date, array $patients): int
    {
        $pool = $this->enrolledByMonth[$date->year * 12 + $date->month] ?? $patients;

        return $pool[mt_rand(0, count($pool) - 1)];
    }

    /**
     * The days an admin would have flagged: every clinic day of an exam
     * week, plus a one-off campus event roughly every third week.
     *
     * Flagging is the ONLY mechanism that lifts a day's volume, so
     * `is_event_day` has exactly one true effect — EVENT_DAY_MULTIPLIER —
     * for the model to recover, rather than a blur of overlapping causes.
     *
     * @return array<string, bool> keyed by date string
     */
    private function seedEventDays(Carbon $start): array
    {
        $labels = [];
        $cursor = $start->copy();
        $oneOffs = ['Enrollment day', 'Medical mission', 'Intramurals', 'Freshman orientation', 'Blood drive'];
        $oneOffIndex = 0;

        while ($cursor->lessThanOrEqualTo(Carbon::today())) {
            $isClinicDay = isset(self::WEEKDAY_BASE_ARRIVALS[$cursor->dayOfWeek]);

            if ($isClinicDay && $this->isExamWeek($cursor)) {
                $labels[$cursor->toDateString()] = 'Examination week';
            } elseif ($isClinicDay && $cursor->dayOfWeek === 3 && (int) $cursor->format('W') % 3 === 0) {
                $labels[$cursor->toDateString()] = $oneOffs[$oneOffIndex++ % count($oneOffs)];
            }

            $cursor->addDay();
        }

        foreach ($labels as $date => $label) {
            EventDay::firstOrCreate(
                ['date' => $date],
                ['label' => $label, 'note' => self::SYNTHETIC_NOTE],
            );
        }

        return array_map(static fn (): bool => true, $labels);
    }

    /**
     * Exam weeks recur on a fixed cycle so the surge sits somewhere the
     * model can find it.
     */
    private function isExamWeek(Carbon $date): bool
    {
        return in_array((int) $date->format('W') % 18, [7, 8], true);
    }

    /**
     * Clear any previously generated demo history so a rerun reproduces the
     * seed exactly instead of stacking a second clinic on top of the first.
     */
    private function purgePreviousHistory(Carbon $start): void
    {
        $patientIds = User::where('email', 'like', 'demo.patient%@flowcast.test')->pluck('id');

        if ($patientIds->isNotEmpty()) {
            DB::table('visit_records')->whereIn('patient_id', $patientIds)->delete();
            DB::table('queue_entries')->whereIn('patient_id', $patientIds)->delete();
            DB::table('appointments')->whereIn('patient_id', $patientIds)->delete();
        }

        DB::table('event_days')->where('note', self::SYNTHETIC_NOTE)->delete();
        DB::table('analytics_snapshots')->where('date', '>=', $start->toDateString())->delete();
    }

    /**
     * Walk every clinic day in the window: the nurse's queue every day, the
     * dentist's and the physician's booked patients on their days.
     *
     * @param  array<int, int>  $patients
     * @param  array{nurse: int, dentist: int, physician: int}  $providers
     * @param  array<string, bool>  $eventDates
     * @return array{days: int, entries: int, bookings: int}
     */
    private function simulate(Carbon $start, array $patients, array $providers, array $eventDates): array
    {
        $totals = ['days' => 0, 'entries' => 0, 'bookings' => 0];
        $now = now();

        for ($date = $start->copy(); $date->lessThan(Carbon::today()); $date->addDay()) {
            $base = self::WEEKDAY_BASE_ARRIVALS[$date->dayOfWeek] ?? null;

            if ($base === null) {
                continue;
            }

            $isEventDay = isset($eventDates[$date->toDateString()]);
            $day = ['appointments' => [], 'entries' => [], 'visits' => []];

            $this->simulateNurseDay($day, $date, $isEventDay ? $base * self::EVENT_DAY_MULTIPLIER : $base, $isEventDay, $patients, $providers['nurse'], $now);

            // Online booking is closed on event days, for everyone.
            if (! $isEventDay) {
                foreach (['dentist', 'physician'] as $profession) {
                    $this->simulateBookedDay($day, $date, $profession, $providers[$profession], $providers['nurse'], $patients);
                }
            }

            $this->persistDay($date, $day);

            $totals['days']++;
            $totals['entries'] += count($day['entries']);
            $totals['bookings'] += count($day['appointments']);
        }

        return $totals;
    }

    /**
     * One day of the nurse's queue: her bookings for the day, Poisson
     * walk-ins, then a single-server service loop that takes a booked
     * patient at their booked time (if they came on time) and otherwise the
     * lowest queue number — the live Call Next rule.
     *
     * @param  array{appointments: array<int, array<string, mixed>>, entries: array<int, array<string, mixed>>, visits: array<int, array<string, mixed>>}  $day
     * @param  array<int, int>  $patients
     */
    private function simulateNurseDay(array &$day, Carbon $date, float $expected, bool $isEventDay, array $patients, int $nurseId, Carbon $now): void
    {
        $open = $date->copy()->setTime(8, 0);
        $close = $date->copy()->setTime(18, 0);
        $cutoff = $close->copy()->addMinutes(self::GRACE_MINUTES_AFTER_CLOSE)->getTimestamp();
        $graceSeconds = self::LATE_GRACE_MINUTES * 60;

        /** @var array<int, array{joined: int, patient_id: int, appointment_key: int|null, slot: int|null}> $arrivals */
        $arrivals = [];

        if (! $isEventDay) {
            foreach ($this->bookedSlots($date, 'nurse') as $slot) {
                $patientId = $this->patientFor($date, $patients);
                $outcome = $this->pickOutcome(self::NURSE_BOOKING_OUTCOMES);
                $status = match ($outcome) {
                    'declined', 'cancelled', 'no_show' => $outcome,
                    default => 'checked_in', // settled once the queue has run
                };
                $key = count($day['appointments']);
                $day['appointments'][] = $this->bookingRow($patientId, $nurseId, $nurseId, $slot, self::NURSE_BOOKING_REASONS[mt_rand(0, count(self::NURSE_BOOKING_REASONS) - 1)], $status);

                if (! in_array($outcome, ['on_time', 'late'], true)) {
                    continue;
                }

                $arrives = $outcome === 'on_time'
                    ? $slot->copy()->addMinutes(mt_rand(-25, 8))
                    : $slot->copy()->addMinutes(mt_rand(self::LATE_GRACE_MINUTES + 1, 45));

                if ($arrives->greaterThanOrEqualTo($close)) {
                    $day['appointments'][$key]['status'] = 'no_show';

                    continue;
                }

                $arrivals[] = [
                    'joined' => max($arrives->getTimestamp(), $open->getTimestamp()),
                    'patient_id' => $patientId,
                    'appointment_key' => $key,
                    'slot' => $slot->getTimestamp(),
                ];
            }
        }

        foreach ($this->arrivalTimes($date, $expected) as $arrivedAt) {
            $arrivals[] = [
                'joined' => $arrivedAt->getTimestamp(),
                'patient_id' => $this->patientFor($date, $patients),
                'appointment_key' => null,
                'slot' => null,
            ];
        }

        usort($arrivals, static fn (array $a, array $b): int => $a['joined'] <=> $b['joined']);

        // Queue numbers follow arrival order, as check-in assigns them.
        $outcomes = [];
        $remaining = array_keys($arrivals);
        $nurseFreeAt = $open->getTimestamp();

        while ($remaining !== []) {
            $clock = $nurseFreeAt;
            $waiting = array_values(array_filter($remaining, fn (int $i): bool => $arrivals[$i]['joined'] <= $clock));

            if ($waiting === []) {
                $nurseFreeAt = min(array_map(fn (int $i): int => $arrivals[$i]['joined'], $remaining));

                continue;
            }

            if ($clock > $cutoff) {
                foreach ($remaining as $i) {
                    $outcomes[$i] = ['status' => 'skipped', 'called' => null, 'completed' => null];
                }

                break;
            }

            $next = $this->nextToCall($arrivals, $waiting, $clock, $graceSeconds);
            $remaining = array_values(array_diff($remaining, [$next]));

            if ($this->chance(self::NOT_THERE_SHARE)) {
                $outcomes[$next] = ['status' => 'skipped', 'called' => $clock, 'completed' => null];
                $nurseFreeAt = $clock + 60;

                continue;
            }

            $completed = $clock + $this->consultMinutes() * 60;
            $outcomes[$next] = ['status' => 'done', 'called' => $clock, 'completed' => $completed];
            $nurseFreeAt = $completed;
        }

        foreach ($arrivals as $i => $arrival) {
            $outcome = $outcomes[$i];
            $entryIndex = count($day['entries']);
            $at = fn (?int $timestamp): ?string => $timestamp === null ? null : Carbon::createFromTimestamp($timestamp, $date->getTimezone())->toDateTimeString();

            $day['entries'][] = [
                'queue_number' => $i + 1,
                // Written with the midnight time Eloquent's date cast uses,
                // so SQLite's string comparison matches it too.
                'queue_date' => $date->copy()->startOfDay()->toDateTimeString(),
                'patient_id' => $arrival['patient_id'],
                'source' => $arrival['appointment_key'] === null ? 'walk_in' : 'appointment',
                'status' => $outcome['status'],
                'appointment_key' => $arrival['appointment_key'],
                'handled_by_id' => $outcome['called'] === null ? null : $nurseId,
                'joined_at' => $at($arrival['joined']),
                'called_at' => $at($outcome['called']),
                'completed_at' => $at($outcome['completed']),
                'created_at' => $now->toDateTimeString(),
                'updated_at' => $now->toDateTimeString(),
            ];

            if ($arrival['appointment_key'] !== null) {
                $day['appointments'][$arrival['appointment_key']]['status'] = $outcome['status'] === 'done' ? 'completed' : 'no_show';
            }

            if ($outcome['status'] === 'done' && $this->chance(self::VISIT_RECORD_SHARE)) {
                $complaint = $arrival['appointment_key'] === null
                    ? self::NURSE_COMPLAINTS[mt_rand(0, count(self::NURSE_COMPLAINTS) - 1)]
                    : $day['appointments'][$arrival['appointment_key']]['reason'];

                $day['visits'][] = $this->visitRow($arrival['patient_id'], $nurseId, $complaint, $at($outcome['completed'])) + ['entry_index' => $entryIndex];
            }
        }
    }

    /**
     * The live Call Next rule: a booked patient whose time has come, and who
     * arrived within the grace, goes first (earliest booking first);
     * otherwise the lowest queue number.
     *
     * @param  array<int, array{joined: int, patient_id: int, appointment_key: int|null, slot: int|null}>  $arrivals
     * @param  array<int, int>  $waiting  arrival indexes, ascending
     */
    private function nextToCall(array $arrivals, array $waiting, int $now, int $graceSeconds): int
    {
        $due = array_filter($waiting, fn (int $i): bool => $arrivals[$i]['slot'] !== null
            && $arrivals[$i]['slot'] <= $now
            && $arrivals[$i]['joined'] <= $arrivals[$i]['slot'] + $graceSeconds);

        if ($due === []) {
            return $waiting[0];
        }

        usort($due, fn (int $a, int $b): int => [$arrivals[$a]['slot'], $a] <=> [$arrivals[$b]['slot'], $b]);

        return $due[0];
    }

    /**
     * The dentist's or the physician's day: bookings in their own hours,
     * seen at the booked time, each completed one with a visit record.
     *
     * @param  array{appointments: array<int, array<string, mixed>>, entries: array<int, array<string, mixed>>, visits: array<int, array<string, mixed>>}  $day
     * @param  array<int, int>  $patients
     */
    private function simulateBookedDay(array &$day, Carbon $date, string $profession, int $providerId, int $nurseId, array $patients): void
    {
        $complaints = $profession === 'dentist' ? self::DENTIST_COMPLAINTS : self::PHYSICIAN_COMPLAINTS;
        $slotMinutes = ClinicSetupSeeder::BOOKING_HOURS[$profession][3];

        foreach ($this->bookedSlots($date, $profession) as $slot) {
            $patientId = $this->patientFor($date, $patients);
            $reason = $complaints[mt_rand(0, count($complaints) - 1)];
            $status = $this->pickOutcome(self::BOOKED_DAY_OUTCOMES);

            $key = count($day['appointments']);
            $day['appointments'][] = $this->bookingRow($patientId, $providerId, $nurseId, $slot, $reason, $status);

            if ($status === 'completed') {
                $seenAt = $slot->copy()->addMinutes(mt_rand(10, max($slotMinutes - 2, 11)))->toDateTimeString();
                $day['visits'][] = $this->visitRow($patientId, $providerId, $reason, $seenAt) + ['appointment_key' => $key];
            }
        }
    }

    /**
     * Distinct booked slot starts for one professional on $date (none on a
     * day they do not work), one booking per slot.
     *
     * @return array<int, Carbon>
     */
    private function bookedSlots(Carbon $date, string $profession): array
    {
        [$days, $start, $end, $slotMinutes] = ClinicSetupSeeder::BOOKING_HOURS[$profession];

        if (! in_array($date->dayOfWeek, $days, true)) {
            return [];
        }

        $slots = [];
        for ($cursor = Carbon::parse($date->toDateString().' '.$start); $cursor->copy()->addMinutes($slotMinutes)->lte(Carbon::parse($date->toDateString().' '.$end)); $cursor->addMinutes($slotMinutes)) {
            $slots[] = $cursor->copy();
        }

        $count = min($this->poisson(self::BOOKINGS_PER_DAY[$profession]), count($slots));
        $chosen = [];

        while (count($chosen) < $count) {
            $chosen[mt_rand(0, count($slots) - 1)] = true;
        }

        ksort($chosen);

        return array_map(fn (int $index): Carbon => $slots[$index], array_keys($chosen));
    }

    /**
     * A booking as the review step leaves it: made a few evenings before,
     * reviewed by the head nurse that night unless the patient withdrew it
     * first.
     *
     * @return array<string, mixed>
     */
    private function bookingRow(int $patientId, int $providerId, int $nurseId, Carbon $slot, string $reason, string $status): array
    {
        $madeAt = $slot->copy()->subDays(mt_rand(1, 6))->setTime(mt_rand(18, 21), mt_rand(0, 59));
        $reviewed = $status !== 'cancelled' || $this->chance(0.5);
        $reviewedAt = $madeAt->copy()->addMinutes(mt_rand(20, 600));

        return [
            'patient_id' => $patientId,
            'provider_id' => $providerId,
            'scheduled_at' => $slot->toDateTimeString(),
            'reason' => $reason,
            'status' => $status,
            'cancel_reason' => $status === 'cancelled' ? 'Schedule conflict with a class.' : null,
            'reviewed_by_id' => $reviewed ? $nurseId : null,
            'reviewed_at' => $reviewed ? $reviewedAt->toDateTimeString() : null,
            'review_note' => $status === 'declined' ? 'That time is already taken by a school activity. Please choose another slot.' : null,
            'created_at' => $madeAt->toDateTimeString(),
            'updated_at' => ($reviewed ? $reviewedAt : $madeAt)->toDateTimeString(),
        ];
    }

    /**
     * A visit record with a placeholder intervention; AlphaSampleDataSeeder
     * later rewrites it, the remarks and the vitals to fit the complaint.
     *
     * @return array<string, mixed>
     */
    private function visitRow(int $patientId, int $createdById, string $complaint, string $at): array
    {
        return [
            'patient_id' => $patientId,
            'created_by_id' => $createdById,
            'complaint' => $complaint,
            'intervention' => 'Assessed and managed in the clinic.',
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }

    /**
     * Write one day, resolving each placeholder key to the id of the row
     * just inserted for it: appointments first, then queue entries (which
     * point at appointments), then visits (which point at either).
     *
     * Queue numbers continue from whatever a developer already created on a
     * generated date, so a rerun never collides with hand-made rows.
     *
     * @param  array{appointments: array<int, array<string, mixed>>, entries: array<int, array<string, mixed>>, visits: array<int, array<string, mixed>>}  $day
     */
    private function persistDay(Carbon $date, array $day): void
    {
        $appointmentIds = [];

        if ($day['appointments'] !== []) {
            DB::table('appointments')->insert($day['appointments']);
            $appointmentIds = $this->lastInsertedIds('appointments', count($day['appointments']));
        }

        $entryIds = [];

        if ($day['entries'] !== []) {
            $queueOffset = (int) DB::table('queue_entries')->whereDate('queue_date', $date)->max('queue_number');

            $rows = array_map(function (array $entry) use ($appointmentIds, $queueOffset): array {
                $key = $entry['appointment_key'];
                unset($entry['appointment_key']);

                return [
                    ...$entry,
                    'queue_number' => $entry['queue_number'] + $queueOffset,
                    'appointment_id' => $key === null ? null : $appointmentIds[$key],
                ];
            }, $day['entries']);

            DB::table('queue_entries')->insert($rows);
            $entryIds = $this->lastInsertedIds('queue_entries', count($rows));
        }

        $visitRows = array_map(function (array $visit) use ($entryIds, $appointmentIds): array {
            $entryIndex = $visit['entry_index'] ?? null;
            $appointmentKey = $visit['appointment_key'] ?? null;
            unset($visit['entry_index'], $visit['appointment_key']);

            return [
                ...$visit,
                'queue_entry_id' => $entryIndex === null ? null : $entryIds[$entryIndex],
                'appointment_id' => $appointmentKey === null ? null : $appointmentIds[$appointmentKey],
            ];
        }, $day['visits']);

        if ($visitRows !== []) {
            DB::table('visit_records')->insert($visitRows);
        }
    }

    /**
     * The ids of the $count rows just inserted into $table, in insertion
     * order. Auto-increment ids are monotonic, so the newest $count ids read
     * back in ascending order are exactly the batch that was written.
     *
     * @return array<int, int>
     */
    private function lastInsertedIds(string $table, int $count): array
    {
        return DB::table($table)
            ->orderByDesc('id')
            ->limit($count)
            ->pluck('id')
            ->reverse()
            ->values()
            ->all();
    }

    /**
     * Poisson walk-in counts per opening hour, spread uniformly at random
     * within each hour and returned in chronological order.
     *
     * @return array<int, Carbon>
     */
    private function arrivalTimes(Carbon $date, float $expected): array
    {
        $times = [];

        foreach (self::HOURLY_SHAPE as $hour => $share) {
            $count = $this->poisson($expected * $share);

            for ($i = 0; $i < $count; $i++) {
                $times[] = $date->copy()->setTime($hour, mt_rand(0, 59), mt_rand(0, 59));
            }
        }

        usort($times, static fn (Carbon $a, Carbon $b): int => $a->getTimestamp() <=> $b->getTimestamp());

        return $times;
    }

    /**
     * @param  array<string, float>  $shares  outcome => share, summing to 1
     */
    private function pickOutcome(array $shares): string
    {
        $roll = mt_rand() / mt_getrandmax();

        foreach ($shares as $outcome => $share) {
            $roll -= $share;

            if ($roll <= 0) {
                return $outcome;
            }
        }

        return array_key_last($shares);
    }

    /**
     * Knuth's algorithm for sampling a Poisson count — arrivals in a fixed
     * window are the textbook case for it, and using it rather than a flat
     * random count is what gives the queue realistic bursts.
     */
    private function poisson(float $lambda): int
    {
        if ($lambda <= 0) {
            return 0;
        }

        $limit = exp(-$lambda);
        $product = 1.0;
        $count = 0;

        do {
            $count++;
            $product *= mt_rand() / mt_getrandmax();
        } while ($product > $limit);

        return $count - 1;
    }

    /**
     * Lognormal consultation length: most consultations are short, a few
     * run long, and none are negative. Clamped to a plausible range.
     */
    private function consultMinutes(): int
    {
        $minutes = (int) round(exp(self::LOG_CONSULT_MU + (self::LOG_CONSULT_SIGMA * $this->standardNormal())));

        return max(self::MIN_CONSULT_MINUTES, min(self::MAX_CONSULT_MINUTES, $minutes));
    }

    /** Box-Muller transform: a standard normal deviate from two uniforms. */
    private function standardNormal(): float
    {
        $u1 = max(mt_rand() / mt_getrandmax(), 1e-12);
        $u2 = mt_rand() / mt_getrandmax();

        return sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
    }

    /**
     * Roll each generated day into the hourly analytics panel using the
     * production command, so the volume model trains on exactly the rows a
     * real deployment would have produced.
     */
    private function snapshot(Carbon $start): void
    {
        $days = 0;

        for ($cursor = $start->copy(); $cursor->lessThan(Carbon::today()); $cursor->addDay()) {
            if (isset(self::WEEKDAY_BASE_ARRIVALS[$cursor->dayOfWeek])) {
                Artisan::call('analytics:snapshot', ['date' => $cursor->toDateString()]);
                $days++;
            }
        }

        $this->command?->info("Snapshotted {$days} day(s) into analytics_snapshots.");
    }
}
