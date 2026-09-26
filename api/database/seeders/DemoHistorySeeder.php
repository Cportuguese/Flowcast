<?php

namespace Database\Seeders;

use App\Models\ClinicSchedule;
use App\Models\EventDay;
use App\Models\User;
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
 * The history is generated from a process that is deliberately KNOWN, so
 * the trained model can be checked against ground truth rather than merely
 * admired:
 *
 * - per-weekday base arrival rates (see WEEKDAY_BASE_ARRIVALS)
 * - a midday dip in arrivals (HOURLY_SHAPE)
 * - a lift of EVENT_DAY_MULTIPLIER on flagged days: every weekday of an
 *   exam week, plus an occasional one-off campus event. That multiplier is
 *   deliberately NOT the 1.5 the old heuristic hardcoded, so it is visible
 *   whether the model learned the real number or inherited the guess
 * - Poisson arrivals within each hour, lognormal consultation lengths
 * - one or two personnel on duty depending on the day's expected load
 * - a FIFO service simulation across those personnel, so joined_at,
 *   called_at and completed_at are mutually consistent rather than random
 *
 * Because waits emerge from the simulated service loop, the true wait
 * scales with people-ahead DIVIDED BY the number of personnel — which is
 * exactly the relationship `people_ahead_per_staff` is there to capture and
 * the old people-ahead x consult-time heuristic cannot express.
 *
 * Re-running wipes and regenerates the demo history; it is not additive.
 */
class DemoHistorySeeder extends Seeder
{
    /** How much history to generate. */
    public const DAYS_OF_HISTORY = 182;

    /** Fixed so a rerun reproduces the same history for the same write-up. */
    private const RANDOM_SEED = 20260926;

    private const PATIENT_COUNT = 240;

    /** Marks event days this seeder owns, so a rerun can clear its own. */
    private const SYNTHETIC_NOTE = 'Synthetic demo data.';

    /**
     * Mean arrivals on an ordinary day, by day of week (0 = Sunday).
     *
     * Set against SECOND_STAFF_THRESHOLD so the clinic runs at a realistic
     * 50-85% utilisation: Wednesday and Friday fall below the threshold and
     * are staffed by one nurse, the rest by two. That deliberately makes
     * Wednesday the worst day to visit despite being one of the quietest —
     * a staffing effect no arrivals-only forecast can see.
     */
    private const WEEKDAY_BASE_ARRIVALS = [1 => 58, 2 => 48, 3 => 40, 4 => 46, 5 => 34];

    /** Share of a day's arrivals landing in each opening hour. */
    private const HOURLY_SHAPE = [
        8 => 0.14, 9 => 0.16, 10 => 0.15, 11 => 0.12, 12 => 0.05,
        13 => 0.10, 14 => 0.12, 15 => 0.09, 16 => 0.07,
    ];

    /**
     * The true lift on a flagged day. Deliberately not the 1.5 the old
     * heuristic hardcoded, so it is visible whether the model learned the
     * real number or simply inherited the guess.
     */
    private const EVENT_DAY_MULTIPLIER = 1.45;

    /** Expected arrivals at or above which a second nurse is rostered. */
    private const SECOND_STAFF_THRESHOLD = 44;

    /** Lognormal consultation length: median e^LOG_CONSULT_MU minutes. */
    private const LOG_CONSULT_MU = 2.35;

    private const LOG_CONSULT_SIGMA = 0.42;

    private const MIN_CONSULT_MINUTES = 3;

    private const MAX_CONSULT_MINUTES = 35;

    /** Arrivals still uncalled this long after closing are marked skipped. */
    private const GRACE_MINUTES_AFTER_CLOSE = 30;

    /** Share of arrivals that came from a booked appointment. */
    private const APPOINTMENT_SHARE = 0.32;

    /** Share of booked appointments the patient never showed up for. */
    private const NO_SHOW_SHARE = 0.12;

    /** Share of completed consultations that produced a visit record. */
    private const VISIT_RECORD_SHARE = 0.85;

    /** @var array<int, string> */
    private const COMPLAINTS = [
        'Headache', 'Fever', 'Sore throat', 'Stomach ache', 'Dizziness',
        'Cough and colds', 'Dysmenorrhea', 'Minor wound', 'Toothache',
        'Body pain', 'Allergic reaction', 'Eye irritation',
    ];

    /** @var array<int, string> */
    private const DIAGNOSES = [
        'Acute upper respiratory infection', 'Tension headache', 'Gastritis',
        'Viral fever', 'Pharyngitis', 'Primary dysmenorrhea',
        'Superficial laceration', 'Allergic rhinitis', 'Conjunctivitis',
        'Musculoskeletal strain', 'Migraine', 'Dehydration',
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoHistorySeeder generates synthetic patients and visits. Refusing to run in production.');

            return;
        }

        mt_srand(self::RANDOM_SEED);

        $this->command?->warn('Generating SYNTHETIC clinic history — not real patient data.');

        $this->ensureSchedules();
        $staff = $this->ensureStaff();
        $patients = $this->ensurePatients();

        $start = Carbon::today()->subDays(self::DAYS_OF_HISTORY);

        $this->purgePreviousHistory($start);

        $eventDates = $this->seedEventDays($start);

        $days = $this->simulate($start, $patients, $staff, $eventDates);

        $this->command?->info("Generated {$days['entries']} queue entries across {$days['days']} clinic day(s).");

        $this->snapshot($start);
    }

    /**
     * Mon-Fri 08:00-17:00, created only if the clinic has no schedule yet.
     */
    private function ensureSchedules(): void
    {
        foreach (array_keys(self::WEEKDAY_BASE_ARRIVALS) as $dayOfWeek) {
            ClinicSchedule::firstOrCreate(
                ['day_of_week' => $dayOfWeek],
                [
                    'start_time' => '08:00:00',
                    'end_time' => '17:00:00',
                    'slot_minutes' => 20,
                    'capacity' => 3,
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * Two nurses to attribute consultations to, so `active_staff` varies.
     *
     * @return array<int, int> user ids
     */
    private function ensureStaff(): array
    {
        $ids = [];

        foreach ([1, 2] as $index) {
            $user = User::firstOrNew(['email' => "demo.nurse{$index}@flowcast.test"]);
            $user->fill([
                'name' => "Demo Nurse {$index}",
                'password' => Hash::make('password'),
            ]);
            $user->role = 'personnel';
            $user->email_verified_at ??= now();
            $user->save();

            $ids[] = $user->id;
        }

        return $ids;
    }

    /**
     * @return array<int, int> patient user ids
     */
    private function ensurePatients(): array
    {
        $existing = User::where('email', 'like', 'demo.patient%@flowcast.test')
            ->pluck('id', 'email')
            ->all();

        $password = Hash::make('password');
        $now = now();
        $new = [];

        for ($i = 1; $i <= self::PATIENT_COUNT; $i++) {
            $email = "demo.patient{$i}@flowcast.test";

            if (isset($existing[$email])) {
                continue;
            }

            $new[] = [
                'name' => "Demo Patient {$i}",
                'email' => $email,
                'password' => $password,
                'role' => 'patient',
                'school_id' => sprintf('2023-%05d', $i),
                'patient_type' => ['student', 'student', 'student', 'faculty', 'staff'][$i % 5],
                'contact_no' => sprintf('0917%07d', $i),
                'email_verified_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($new, 200) as $chunk) {
            DB::table('users')->insert($chunk);
        }

        return User::where('email', 'like', 'demo.patient%@flowcast.test')->pluck('id')->all();
    }

    /**
     * The days an admin would have flagged: every weekday of an exam week,
     * plus a one-off campus event roughly every third week.
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
            $isWeekday = isset(self::WEEKDAY_BASE_ARRIVALS[$cursor->dayOfWeek]);

            if ($isWeekday && $this->isExamWeek($cursor)) {
                $labels[$cursor->toDateString()] = 'Examination week';
            } elseif ($isWeekday && $cursor->dayOfWeek === 3 && (int) $cursor->format('W') % 3 === 0) {
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

        if ($patientIds->isEmpty()) {
            return;
        }

        $entryIds = DB::table('queue_entries')->whereIn('patient_id', $patientIds)->pluck('id');

        DB::table('visit_records')->whereIn('queue_entry_id', $entryIds)->delete();
        DB::table('queue_entries')->whereIn('id', $entryIds)->delete();
        DB::table('appointments')->whereIn('patient_id', $patientIds)->delete();
        DB::table('event_days')->where('note', self::SYNTHETIC_NOTE)->delete();
        DB::table('analytics_snapshots')->where('date', '>=', $start->toDateString())->delete();
    }

    /**
     * Walk every clinic day in the window, generate its arrivals, serve them
     * through a FIFO queue with as many parallel servers as there are
     * personnel on duty, and write the day before moving on.
     *
     * @param  array<int, int>  $patients
     * @param  array<int, int>  $staff
     * @param  array<string, bool>  $eventDates
     * @return array{days: int, entries: int}
     */
    private function simulate(Carbon $start, array $patients, array $staff, array $eventDates): array
    {
        $dayCount = 0;
        $entryCount = 0;
        $now = now();
        $cursor = $start->copy();

        while ($cursor->lessThan(Carbon::today())) {
            $base = self::WEEKDAY_BASE_ARRIVALS[$cursor->dayOfWeek] ?? null;

            if ($base === null) {
                $cursor->addDay();

                continue;
            }

            $expected = $base;

            if (isset($eventDates[$cursor->toDateString()])) {
                $expected *= self::EVENT_DAY_MULTIPLIER;
            }

            $onDuty = $expected >= self::SECOND_STAFF_THRESHOLD ? 2 : 1;
            $day = $this->simulateDay($cursor, $expected, $onDuty, $patients, $staff, $now);

            $this->persistDay($cursor, $day);

            $entryCount += count($day['entries']);
            $dayCount++;
            $cursor->addDay();
        }

        return ['days' => $dayCount, 'entries' => $entryCount];
    }

    /**
     * Write one day, resolving each entry's `appointment_key` placeholder to
     * the id of the appointment row just inserted for it.
     *
     * Rows a developer created by hand on a generated date are left alone:
     * queue numbers continue from whatever is already there, and the ids
     * mapped back are only the ones this call inserted. Re-running therefore
     * replaces the synthetic history without touching anything else.
     *
     * @param  array{entries: array<int, array<string, mixed>>, appointments: array<int, array<string, mixed>>, visits: array<int, array<string, mixed>>}  $day
     */
    private function persistDay(Carbon $date, array $day): void
    {
        $appointmentIds = [];

        if ($day['appointments'] !== []) {
            DB::table('appointments')->insert($day['appointments']);

            $appointmentIds = $this->lastInsertedIds('appointments', count($day['appointments']));
        }

        $checkedInKeys = [];

        foreach ($day['appointments'] as $index => $appointment) {
            if ($appointment['status'] === 'checked_in') {
                $checkedInKeys[$index] = $appointmentIds[$index] ?? null;
            }
        }

        $queueOffset = (int) DB::table('queue_entries')->where('queue_date', $date->toDateString())->max('queue_number');
        $entryRows = [];

        foreach ($day['entries'] as $entry) {
            $key = $entry['appointment_key'];
            unset($entry['appointment_key']);

            $entry['queue_number'] += $queueOffset;
            $entry['appointment_id'] = $key === null ? null : ($checkedInKeys[$key] ?? null);
            $entryRows[] = $entry;
        }

        if ($entryRows === []) {
            return;
        }

        DB::table('queue_entries')->insert($entryRows);

        $entryIds = $this->lastInsertedIds('queue_entries', count($entryRows));
        $visitRows = [];

        foreach ($day['visits'] as $visit) {
            $entryId = $entryIds[$visit['entry_index']] ?? null;

            if ($entryId === null) {
                continue;
            }

            unset($visit['entry_index']);
            $visitRows[] = $visit + [
                'queue_entry_id' => $entryId,
                'complaint' => $this->complaint(),
                'diagnosis' => $this->diagnosis(),
                'treatment' => 'Advised rest and fluids.',
            ];
        }

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
     * One clinic day: Poisson arrivals shaped across the opening hours, then
     * served first-come-first-served by $onDuty parallel personnel.
     *
     * @param  array<int, int>  $patients
     * @param  array<int, int>  $staff
     * @return array{entries: array<int, array<string, mixed>>, appointments: array<int, array<string, mixed>>, visits: array<int, array<string, mixed>>}
     */
    private function simulateDay(Carbon $date, float $expected, int $onDuty, array $patients, array $staff, Carbon $now): array
    {
        $arrivals = $this->arrivalTimes($date, $expected);

        if ($arrivals === []) {
            return ['entries' => [], 'appointments' => [], 'visits' => []];
        }

        $cutoff = $date->copy()->setTime(17, 0)->addMinutes(self::GRACE_MINUTES_AFTER_CLOSE);

        /** Next free moment for each nurse on duty. */
        $serverFreeAt = array_fill(0, $onDuty, $date->copy()->setTime(8, 0)->getTimestamp());

        $entries = [];
        $appointments = [];
        $visits = [];
        $chosenPatients = $this->pickPatients($patients, count($arrivals));

        foreach ($arrivals as $index => $arrivedAt) {
            $earliest = min($serverFreeAt);
            $server = (int) array_search($earliest, $serverFreeAt, true);
            $calledAt = $arrivedAt->copy()->setTimestamp(max($earliest, $arrivedAt->getTimestamp()));

            $isAppointment = $this->chance(self::APPOINTMENT_SHARE);
            $patientId = $chosenPatients[$index];
            $appointmentKey = null;

            if ($isAppointment) {
                $appointmentKey = count($appointments);
                $appointments[] = [
                    'patient_id' => $patientId,
                    'scheduled_at' => $arrivedAt->copy()->startOfHour()->toDateTimeString(),
                    'reason' => 'Consultation',
                    'status' => 'checked_in',
                    'created_at' => $arrivedAt->toDateTimeString(),
                    'updated_at' => $arrivedAt->toDateTimeString(),
                ];
            }

            if ($calledAt->greaterThan($cutoff)) {
                $entries[] = $this->entryRow($date, $index + 1, $patientId, $isAppointment, 'skipped', $arrivedAt, null, null, null, $appointmentKey, $now);

                continue;
            }

            $completedAt = $calledAt->copy()->addMinutes($this->consultMinutes());
            $serverFreeAt[$server] = $completedAt->getTimestamp();
            $handledBy = $staff[$server % count($staff)];

            $entries[] = $this->entryRow($date, $index + 1, $patientId, $isAppointment, 'done', $arrivedAt, $calledAt, $completedAt, $handledBy, $appointmentKey, $now);

            if ($this->chance(self::VISIT_RECORD_SHARE)) {
                $visits[] = [
                    'entry_index' => count($entries) - 1,
                    'patient_id' => $patientId,
                    'created_by_id' => $handledBy,
                    'created_at' => $completedAt->toDateTimeString(),
                    'updated_at' => $completedAt->toDateTimeString(),
                ];
            }
        }

        return [
            'entries' => $entries,
            'appointments' => array_merge($appointments, $this->noShowAppointments($date, $patients)),
            'visits' => $visits,
        ];
    }

    /**
     * `appointment_key` is a placeholder resolved to a real appointment id
     * once the appointment rows have been inserted.
     *
     * @return array<string, mixed>
     */
    private function entryRow(
        Carbon $date,
        int $queueNumber,
        int $patientId,
        bool $isAppointment,
        string $status,
        Carbon $arrivedAt,
        ?Carbon $calledAt,
        ?Carbon $completedAt,
        ?int $handledBy,
        ?int $appointmentKey,
        Carbon $now,
    ): array {
        return [
            'queue_number' => $queueNumber,
            'queue_date' => $date->toDateString(),
            'patient_id' => $patientId,
            'source' => $isAppointment ? 'appointment' : 'walk_in',
            'status' => $status,
            'appointment_key' => $appointmentKey,
            'handled_by_id' => $handledBy,
            'joined_at' => $arrivedAt->toDateTimeString(),
            'called_at' => $calledAt?->toDateTimeString(),
            'completed_at' => $completedAt?->toDateTimeString(),
            'created_at' => $now->toDateTimeString(),
            'updated_at' => $now->toDateTimeString(),
        ];
    }

    /**
     * Booked appointments nobody turned up for. They never enter the queue,
     * so they feed the no-show report rather than the wait-time model.
     *
     * @param  array<int, int>  $patients
     * @return array<int, array<string, mixed>>
     */
    private function noShowAppointments(Carbon $date, array $patients): array
    {
        $rows = [];
        $count = $this->poisson(self::NO_SHOW_SHARE * 4);

        for ($i = 0; $i < $count; $i++) {
            $scheduledAt = $date->copy()->setTime(9 + ($i % 7), 0);

            $rows[] = [
                'patient_id' => $patients[mt_rand(0, count($patients) - 1)],
                'scheduled_at' => $scheduledAt->toDateTimeString(),
                'reason' => 'Consultation',
                'status' => 'no_show',
                'created_at' => $scheduledAt->toDateTimeString(),
                'updated_at' => $scheduledAt->toDateTimeString(),
            ];
        }

        return $rows;
    }

    /**
     * Poisson arrival counts per opening hour, spread uniformly at random
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

    private function chance(float $probability): bool
    {
        return (mt_rand() / mt_getrandmax()) < $probability;
    }

    /**
     * @param  array<int, int>  $patients
     * @return array<int, int>
     */
    private function pickPatients(array $patients, int $count): array
    {
        $picked = [];
        $total = count($patients);

        for ($i = 0; $i < $count; $i++) {
            $picked[] = $patients[mt_rand(0, $total - 1)];
        }

        return $picked;
    }

    /**
     * A small fixed vocabulary rather than random sentences, so the
     * diagnosis columns look like a clinic's own shorthand and the reports
     * that bucket them have something coherent to bucket.
     */
    private function complaint(): string
    {
        return self::COMPLAINTS[mt_rand(0, count(self::COMPLAINTS) - 1)];
    }

    private function diagnosis(): string
    {
        return self::DIAGNOSES[mt_rand(0, count(self::DIAGNOSES) - 1)];
    }

    /**
     * Roll each generated day into the hourly analytics panel using the
     * production command, so the volume model trains on exactly the rows a
     * real deployment would have produced.
     */
    private function snapshot(Carbon $start): void
    {
        $cursor = $start->copy();
        $days = 0;

        while ($cursor->lessThan(Carbon::today())) {
            if (isset(self::WEEKDAY_BASE_ARRIVALS[$cursor->dayOfWeek])) {
                Artisan::call('analytics:snapshot', ['date' => $cursor->toDateString()]);
                $days++;
            }

            $cursor->addDay();
        }

        $this->command?->info("Snapshotted {$days} day(s) into analytics_snapshots.");
    }
}
