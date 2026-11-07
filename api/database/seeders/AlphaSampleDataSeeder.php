<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\EventDay;
use App\Models\InventoryLog;
use App\Models\MedicalCertificate;
use App\Models\Medicine;
use App\Models\ProviderSchedule;
use App\Models\QueueEntry;
use App\Models\User;
use App\Models\VisitRecord;
use Database\Seeders\Concerns\BackdatesRecords;
use Database\Seeders\Concerns\SamplesClinicalCases;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * SAMPLE DATA for the capstone tests, layered on the synthetic history
 * DemoHistorySeeder writes. It makes that history presentable without
 * touching anything the forecasting models learn from (queue timings,
 * bookings and daily volumes stay exactly as simulated):
 *
 * - each visit's intervention, remarks, vital signs and referral made to
 *   agree with its complaint
 * - a medicine inventory whose batches, deliveries and dispensing logs add
 *   up, with low-stock, expiring-soon and expired-batch cases to show
 * - medical certificates for recent visits, issued by whoever saw the
 *   patient, and a short history with a certificate for the demo patient
 * - an event day inside the coming week (so the 7-day forecast shows its
 *   lift and booking that date is refused), and a week of bookings ahead
 *   for all three professionals — some still under review for the nurse
 *
 * Nothing here is a real patient, visit or measurement.
 */
class AlphaSampleDataSeeder extends Seeder
{
    use BackdatesRecords;
    use SamplesClinicalCases;

    private const RANDOM_SEED = 20261012;

    /** Visits this recent dispensed medicines from the tracked inventory. */
    private const DISPENSING_WINDOW_DAYS = 90;

    /** Visits this recent may come with a medical certificate. */
    private const CERTIFICATE_WINDOW_DAYS = 30;

    /** Share of recent rest-worthy visits that ended with a certificate. */
    private const CERTIFICATE_SHARE = 0.35;

    /** Overridable: the beta dataset dispenses from, and certifies, years of visits. */
    public int $dispensingWindowDays = self::DISPENSING_WINDOW_DAYS;

    public int $certificateWindowDays = self::CERTIFICATE_WINDOW_DAYS;

    public float $certificateShare = self::CERTIFICATE_SHARE;

    /**
     * Stock the clinic through the whole dispensing window, with a delivery
     * about every quarter, instead of only the last 100 days. Without it a
     * multi-year history shows no stock movements before the alpha's window.
     */
    public bool $restockHistory = false;

    /** Days between deliveries of the same medicine when $restockHistory is on. */
    private const RESTOCK_EVERY_DAYS = 90;

    /** @var array<string, array{0: string, 1: string, 2: int, 3: int}> name => [category, unit, reorder level, reorder quantity] */
    private const MEDICINES = [
        'Paracetamol 500 mg' => ['Analgesic / antipyretic', 'tablet', 150, 1000],
        'Mefenamic Acid 500 mg' => ['Analgesic', 'capsule', 80, 500],
        'Ibuprofen 400 mg' => ['Anti-inflammatory', 'tablet', 60, 300],
        'Cetirizine 10 mg' => ['Antihistamine', 'tablet', 40, 200],
        'Loperamide 2 mg' => ['Antidiarrheal', 'capsule', 30, 100],
        'Aluminum Hydroxide + Magnesium Hydroxide' => ['Antacid', 'tablet', 60, 300],
        'Oral Rehydration Salts' => ['Rehydration', 'sachet', 40, 150],
        'Hyoscine-N-Butylbromide 10 mg' => ['Antispasmodic', 'tablet', 30, 150],
        'Lagundi 600 mg' => ['Herbal cough remedy', 'tablet', 60, 300],
        'Meclizine 25 mg' => ['Antivertigo', 'tablet', 20, 100],
        'Salbutamol 2.5 mg Nebule' => ['Bronchodilator', 'nebule', 10, 40],
        'Throat Lozenge' => ['Sore throat relief', 'piece', 60, 300],
        'Sterile Gauze Pad 4x4' => ['Wound care', 'piece', 60, 300],
        'Adhesive Bandage' => ['Wound care', 'piece', 80, 400],
        'Elastic Bandage 3 in' => ['Wound care', 'roll', 6, 20],
        'Povidone-Iodine 10% Solution' => ['Antiseptic', 'bottle', 4, 12],
        'Isopropyl Alcohol 70%' => ['Antiseptic', 'bottle', 6, 24],
    ];

    /**
     * Batches as [received days ago, expires in days, units left today] for
     * the medicines that should show an inventory alert. Everything else
     * gets an older batch still in use plus a recent delivery.
     *
     * @var array<string, array<int, array{0: int, 1: int, 2: int}>>
     */
    private const STOCK_PLANS = [
        'Cetirizine 10 mg' => [[100, 420, 18]],                           // low stock
        'Hyoscine-N-Butylbromide 10 mg' => [[100, 380, 12]],              // low stock
        'Elastic Bandage 3 in' => [[120, 900, 4]],                        // low stock
        'Loperamide 2 mg' => [[160, 18, 26], [20, 540, 100]],             // expiring soon
        'Povidone-Iodine 10% Solution' => [[150, 27, 3], [25, 700, 12]],  // expiring soon
        'Salbutamol 2.5 mg Nebule' => [[200, -9, 6], [12, 420, 24]],      // expired, waiting to be disposed
    ];

    /** @var array<string, array{0: int, 1: int}> complaint => [min, max] rest days */
    private const REST_DAYS = [
        'Fever' => [1, 3],
        'Fever for three days' => [2, 3],
        'Cough and colds' => [1, 2],
        'Persistent cough' => [1, 2],
        'Sore throat' => [1, 2],
        'Stomach ache' => [1, 2],
        'Dysmenorrhea' => [1, 1],
        'Body pain' => [2, 3],
        'Toothache, for extraction' => [1, 1],
    ];

    /** How many bookings each professional has on each of the coming days. */
    private const UPCOMING_PER_DAY = ['nurse' => [2, 4], 'dentist' => [3, 6], 'physician' => [2, 4]];

    /**
     * A first-year student who signed up on the app this week and has never
     * been to the clinic. Their ID number is still only what they typed, so
     * their booking request shows "ID unchecked" and the nurse checks their
     * card when they come in.
     */
    public const NEWCOMER_EMAIL = 'kristine.manalo@flowcast.test';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('AlphaSampleDataSeeder writes sample patients. Refusing to run in production.');

            return;
        }

        mt_srand(self::RANDOM_SEED);

        $providers = User::providers()->pluck('id', 'profession')->all();
        $nurseId = $providers['nurse'] ?? null;

        $dispensing = $this->enrichVisits();
        [$demoDispensing, $certifiedVisitId] = $this->seedDemoPatientHistory($providers);

        $this->seedInventory([...$dispensing, ...$demoDispensing], $nurseId);
        $this->seedCertificates($certifiedVisitId);
        $this->seedUpcoming($providers);
    }

    /**
     * Give every existing visit an intervention, remarks, vital signs and
     * (rarely) a referral that fit its complaint, and collect the medicines
     * the recent ones dispensed.
     *
     * @return array<int, array{visit_id: int, at: Carbon, user_id: int, medicines: array<string, int>}>
     */
    private function enrichVisits(): array
    {
        $windowStart = Carbon::today()->subDays($this->dispensingWindowDays);
        $dispensing = [];

        VisitRecord::query()
            ->select(['id', 'complaint', 'created_by_id', 'created_at'])
            ->chunkById(500, function (Collection $visits) use ($windowStart, &$dispensing) {
                DB::transaction(function () use ($visits, $windowStart, &$dispensing) {
                    foreach ($visits as $visit) {
                        $case = $this->caseFor($visit->complaint);

                        VisitRecord::whereKey($visit->id)->update([
                            'intervention' => $case['intervention'],
                            'remarks' => $case['remarks'],
                            'referred_to' => $case['referral'],
                            ...$this->vitalsFor($case['febrile'], $visit->complaint),
                        ]);

                        if ($case['medicines'] !== [] && $visit->created_at->gte($windowStart)) {
                            $dispensing[] = [
                                'visit_id' => $visit->id,
                                'at' => $visit->created_at->copy(),
                                'user_id' => $visit->created_by_id,
                                'medicines' => $case['medicines'],
                            ];
                        }
                    }
                });
            });

        $this->command?->info('Matched intervention, remarks and vital signs to the complaint on every visit.');

        return $dispensing;
    }

    /**
     * The demo patient's own history (the account used on the phone): a
     * cough as a walk-in three weeks ago, a dental check-up with the dentist,
     * a fever last week booked with the nurse that ended with a medical
     * certificate, and a booking they cancelled.
     *
     * @param  array<string, int>  $providers  profession => user id
     * @return array{0: array<int, array{visit_id: int, at: Carbon, user_id: int, medicines: array<string, int>}>, 1: int|null}
     */
    private function seedDemoPatientHistory(array $providers): array
    {
        $patient = User::where('email', DevUsersSeeder::PATIENT_EMAIL)->first();
        $nurseId = $providers['nurse'] ?? null;
        $dentistId = $providers['dentist'] ?? null;

        if (! $patient || ! $nurseId || VisitRecord::where('patient_id', $patient->id)->exists()) {
            return [[], null];
        }

        $dispensing = [];
        $certifiedVisitId = null;
        $nurseDays = ClinicSetupSeeder::CLINIC_DAYS;

        // [days ago, booked at, joined, called, done, complaint, booking reason]
        $queueVisits = [
            [23, null, '10:15', '10:28', '10:39', 'Cough and colds', null],
            [7, '13:40', '13:38', '13:40', '13:52', 'Fever', 'Fever since last night'],
        ];

        foreach ($queueVisits as [$daysAgo, $bookedAt, $joined, $called, $done, $complaint, $reason]) {
            $day = $this->clinicDayOnOrBefore(Carbon::today()->subDays($daysAgo), $nurseDays);
            $at = fn (string $time): Carbon => Carbon::parse($day->toDateString().' '.$time);

            $appointment = $bookedAt
                ? $this->createAt(Appointment::class, [
                    'patient_id' => $patient->id,
                    'provider_id' => $nurseId,
                    'scheduled_at' => $at($bookedAt),
                    'reason' => $reason,
                    'status' => 'completed',
                    'reviewed_by_id' => $nurseId,
                    'reviewed_at' => $day->copy()->subDay()->setTime(20, 5),
                ], $day->copy()->subDay()->setTime(19, 12))
                : null;

            $entry = $this->createAt(QueueEntry::class, [
                'queue_number' => (int) QueueEntry::whereDate('queue_date', $day)->max('queue_number') + 1,
                'queue_date' => $day,
                'patient_id' => $patient->id,
                'source' => $appointment ? 'appointment' : 'walk_in',
                'status' => 'done',
                'appointment_id' => $appointment?->id,
                'handled_by_id' => $nurseId,
                'joined_at' => $at($joined),
                'called_at' => $at($called),
                'completed_at' => $at($done),
            ], $at($joined));

            $case = $this->caseFor($complaint);
            $visit = $this->createAt(VisitRecord::class, [
                'queue_entry_id' => $entry->id,
                'patient_id' => $patient->id,
                'created_by_id' => $nurseId,
                'complaint' => $complaint,
                'intervention' => $case['intervention'],
                'remarks' => $case['remarks'],
                ...$this->vitalsFor($case['febrile'] || $complaint === 'Fever', $complaint),
            ], $at($done));

            $dispensing[] = ['visit_id' => $visit->id, 'at' => $at($done), 'user_id' => $nurseId, 'medicines' => $case['medicines']];
            $certifiedVisitId = $complaint === 'Fever' ? $visit->id : $certifiedVisitId;

            // Keep that day's hourly analytics in step with the added visit.
            Artisan::call('analytics:snapshot', ['date' => $day->toDateString()]);
        }

        if ($dentistId) {
            $dentalDay = $this->clinicDayOnOrBefore(Carbon::today()->subDays(12), ClinicSetupSeeder::BOOKING_HOURS['dentist'][0]);
            $slot = $this->freeSlot($dentistId, $dentalDay, '10:30');

            if ($slot) {
                $booking = $this->createAt(Appointment::class, [
                    'patient_id' => $patient->id,
                    'provider_id' => $dentistId,
                    'scheduled_at' => $slot,
                    'reason' => 'Dental check-up and cleaning',
                    'status' => 'completed',
                    'reviewed_by_id' => $nurseId,
                    'reviewed_at' => $dentalDay->copy()->subDays(2)->setTime(21, 0),
                ], $dentalDay->copy()->subDays(2)->setTime(19, 30));

                $case = $this->caseFor('Dental check-up and cleaning');
                $this->createAt(VisitRecord::class, [
                    'appointment_id' => $booking->id,
                    'patient_id' => $patient->id,
                    'created_by_id' => $dentistId,
                    'complaint' => 'Dental check-up and cleaning',
                    'intervention' => $case['intervention'],
                    'remarks' => $case['remarks'],
                    ...$this->vitalsFor(false),
                ], $slot->copy()->addMinutes(25));
            }
        }

        $cancelledDay = $this->clinicDayOnOrBefore(Carbon::today()->subDays(15), $nurseDays);
        $this->createAt(Appointment::class, [
            'patient_id' => $patient->id,
            'provider_id' => $nurseId,
            'scheduled_at' => Carbon::parse($cancelledDay->toDateString().' 09:00'),
            'reason' => 'Medical certificate for enrollment',
            'status' => 'cancelled',
            'cancel_reason' => 'Conflict with a class schedule',
        ], $cancelledDay->copy()->subDays(2)->setTime(20, 5));

        return [$dispensing, $certifiedVisitId];
    }

    /**
     * Catalog, batches and logs. Each batch's quantity received is what was
     * dispensed from it plus what is left, and dispensing follows the same
     * earliest-expiry-first rule as InventoryService::stockOut, so every
     * batch reconciles with its own log.
     *
     * @param  array<int, array{visit_id: int, at: Carbon, user_id: int|null, medicines: array<string, int>}>  $dispensing
     */
    private function seedInventory(array $dispensing, ?int $nurseId): void
    {
        if (Medicine::exists()) {
            $this->command?->warn('Medicines already exist; inventory left untouched.');

            return;
        }

        $today = Carbon::today();
        $medicineIds = [];
        $batches = [];

        foreach (self::MEDICINES as $name => [$category, $unit, $reorderLevel, $reorderQuantity]) {
            $medicineIds[$name] = $this->createAt(Medicine::class, [
                'name' => $name,
                'category' => $category,
                'unit' => $unit,
                'reorder_level' => $reorderLevel,
                'reorder_quantity' => $reorderQuantity,
                'is_active' => true,
            ], $today->copy()->subDays(max(210, $this->dispensingWindowDays + 30))->setTime(9, 0))->id;

            $plan = self::STOCK_PLANS[$name] ?? [
                [100, mt_rand(380, 520), (int) round($reorderLevel * 1.4)],
                [30, mt_rand(640, 800), $reorderQuantity],
            ];

            // [received days ago, expires in days, units left today, stop using it
            // this many days ago (null = until it expires or runs out)]
            $schedule = [
                ...($this->restockHistory ? $this->earlierDeliveries($plan) : []),
                ...array_map(fn (array $batch): array => [...$batch, null], $plan),
            ];

            foreach ($schedule as $n => [$receivedDaysAgo, $expiresInDays, $left, $untilDaysAgo]) {
                $received = $today->copy()->subDays($receivedDaysAgo);
                $batches[$name][] = [
                    'number' => sprintf('%s-%s-%02d', strtoupper(substr((string) preg_replace('/[^A-Za-z]/', '', $name), 0, 3)), $received->format('ym'), $n + 1),
                    'received' => $received,
                    'expiry' => $today->copy()->addDays($expiresInDays),
                    'until' => $untilDaysAgo === null ? null : $today->copy()->subDays($untilDaysAgo),
                    'left' => $left,
                    'used' => [],
                ];
            }
        }

        $events = $this->routineUse($nurseId);
        foreach ($dispensing as $visit) {
            foreach ($visit['medicines'] as $name => $quantity) {
                $events[] = ['medicine' => $name, 'quantity' => $quantity, 'at' => $visit['at'], 'visit_id' => $visit['visit_id'], 'user_id' => $visit['user_id'], 'notes' => 'Dispensed during visit'];
            }
        }
        usort($events, fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        foreach ($events as $event) {
            $day = $event['at']->copy()->startOfDay();
            $pick = null;

            foreach ($batches[$event['medicine']] ?? [] as $index => $batch) {
                $usable = $batch['received']->lte($day) && $batch['expiry']->gte($day)
                    && ($batch['until'] === null || $batch['until']->gt($day));

                if ($usable && ($pick === null || $batch['expiry']->lt($batches[$event['medicine']][$pick]['expiry']))) {
                    $pick = $index;
                }
            }

            if ($pick !== null) {
                $batches[$event['medicine']][$pick]['used'][] = $event;
            }
        }

        $logs = [];
        foreach ($batches as $name => $medicineBatches) {
            foreach ($medicineBatches as $batch) {
                $received = array_sum(array_column($batch['used'], 'quantity')) + $batch['left'];

                // A delivery nothing was ever dispensed from was never ordered.
                if ($received === 0) {
                    continue;
                }

                $receivedAt = $batch['received']->copy()->setTime(9, 30);

                $batchId = DB::table('medicine_batches')->insertGetId([
                    'medicine_id' => $medicineIds[$name],
                    'batch_number' => $batch['number'],
                    'expiry_date' => $batch['expiry']->toDateString(),
                    'received_date' => $batch['received']->toDateString(),
                    'quantity_received' => $received,
                    'quantity_remaining' => $batch['left'],
                    'created_at' => $receivedAt->toDateTimeString(),
                    'updated_at' => $receivedAt->toDateTimeString(),
                ]);

                $logs[] = $this->logRow($medicineIds[$name], $batchId, InventoryLog::TYPE_STOCK_IN, $received, $receivedAt, $nurseId, null, 'Delivery from supplier');

                foreach ($batch['used'] as $event) {
                    $logs[] = $this->logRow($medicineIds[$name], $batchId, InventoryLog::TYPE_STOCK_OUT, $event['quantity'], $event['at'], $event['user_id'], $event['visit_id'], $event['notes']);
                }
            }
        }

        foreach (array_chunk($logs, 500) as $chunk) {
            DB::table('inventory_logs')->insert($chunk);
        }

        $this->command?->info(sprintf(
            'Stocked %d medicines in %d batches with %d inventory log entries.',
            count($medicineIds),
            array_sum(array_map('count', $batches)),
            count($logs),
        ));
    }

    /**
     * The deliveries before the plan's first one, working backwards about a
     * quarter apart until the dispensing window is covered. Each is used only
     * until the next delivery arrives, so a batch is what one quarter used —
     * all of it, since an older batch has nothing left to count.
     *
     * @param  array<int, array{0: int, 1: int, 2: int}>  $plan
     * @return array<int, array{0: int, 1: int, 2: int, 3: int}>
     */
    private function earlierDeliveries(array $plan): array
    {
        $next = max(array_column($plan, 0));
        $earlier = [];

        while ($next < $this->dispensingWindowDays + 20) {
            $received = $next + self::RESTOCK_EVERY_DAYS + mt_rand(-10, 10);
            $shelfLife = mt_rand(420, 720);

            // [received days ago, expires in days, left, stop using days ago]
            array_unshift($earlier, [$received, $shelfLife - $received, 0, $next]);
            $next = $received;
        }

        return $earlier;
    }

    /**
     * Supplies the clinic uses itself rather than giving to a patient: a
     * bottle of alcohol opened every Monday, povidone-iodine every other
     * Wednesday, and a first-aid kit refill for the PE department on the
     * first Friday of each month.
     *
     * @return array<int, array{medicine: string, quantity: int, at: Carbon, visit_id: null, user_id: int|null, notes: string}>
     */
    private function routineUse(?int $nurseId): array
    {
        $events = [];
        $today = Carbon::today();

        for ($day = $today->copy()->subDays($this->dispensingWindowDays); $day->lt($today); $day->addDay()) {
            $use = fn (string $medicine, int $quantity, string $time, string $notes): array => [
                'medicine' => $medicine, 'quantity' => $quantity, 'at' => Carbon::parse($day->toDateString().' '.$time),
                'visit_id' => null, 'user_id' => $nurseId, 'notes' => $notes,
            ];

            if ($day->isMonday()) {
                $events[] = $use('Isopropyl Alcohol 70%', 1, '08:05', 'Opened for clinic use');
            }

            if ($day->isWednesday() && $day->weekOfYear % 2 === 0) {
                $events[] = $use('Povidone-Iodine 10% Solution', 1, '08:10', 'Opened for clinic use');
            }

            if ($day->isFriday() && $day->day <= 7) {
                $events[] = $use('Sterile Gauze Pad 4x4', 10, '15:30', 'Restocked the PE department first-aid kit');
                $events[] = $use('Adhesive Bandage', 20, '15:30', 'Restocked the PE department first-aid kit');
            }
        }

        return $events;
    }

    /**
     * @return array<string, mixed>
     */
    private function logRow(int $medicineId, int $batchId, string $type, int $quantity, Carbon $at, ?int $userId, ?int $visitId, string $notes): array
    {
        return [
            'medicine_id' => $medicineId,
            'medicine_batch_id' => $batchId,
            'user_id' => $userId,
            'type' => $type,
            'quantity' => $quantity,
            'visit_record_id' => $visitId,
            'notes' => $notes,
            'created_at' => $at->toDateTimeString(),
            'updated_at' => $at->toDateTimeString(),
        ];
    }

    /**
     * Certificates for about a third of recent visits whose complaint
     * usually earns a rest day, issued by whoever saw the patient, always
     * including the demo patient's fever. PDFs are not written here: the
     * download endpoint renders a missing file from these rows on first
     * request.
     */
    private function seedCertificates(?int $alwaysVisitId): void
    {
        if (MedicalCertificate::exists()) {
            $this->command?->warn('Medical certificates already exist; none added.');

            return;
        }

        $sequence = [];
        $rows = [];

        VisitRecord::query()
            ->where('created_at', '>=', Carbon::today()->subDays($this->certificateWindowDays))
            ->whereIn('complaint', array_keys(self::REST_DAYS))
            ->orderBy('created_at')
            ->get(['id', 'patient_id', 'created_by_id', 'complaint', 'created_at'])
            ->each(function (VisitRecord $visit) use ($alwaysVisitId, &$sequence, &$rows) {
                $always = $visit->id === $alwaysVisitId;

                if (! $always && ! $this->chance($this->certificateShare)) {
                    return;
                }

                [$min, $max] = self::REST_DAYS[$visit->complaint];
                $restDays = $always ? 2 : mt_rand($min, $max);
                $validFrom = $visit->created_at->copy()->startOfDay();
                $sequence[$validFrom->year] = ($sequence[$validFrom->year] ?? 0) + 1;
                $number = sprintf('MC-%d-%04d', $validFrom->year, $sequence[$validFrom->year]);
                $issuedAt = $visit->created_at->copy()->addMinutes(mt_rand(2, 9))->toDateTimeString();

                $rows[] = [
                    'visit_record_id' => $visit->id,
                    'patient_id' => $visit->patient_id,
                    'issued_by_id' => $visit->created_by_id,
                    'certificate_no' => $number,
                    'rest_days' => $restDays,
                    'remarks' => $this->certificateRemarks($visit->complaint),
                    'valid_from' => $validFrom->toDateString(),
                    'valid_until' => $validFrom->copy()->addDays($restDays - 1)->toDateString(),
                    'file_path' => sprintf('certificates/%d/%s.pdf', $validFrom->year, $number),
                    'created_at' => $issuedAt,
                    'updated_at' => $issuedAt,
                ];
            });

        DB::table('medical_certificates')->insert($rows);

        $this->command?->info('Issued '.count($rows).' sample medical certificates.');
    }

    private function certificateRemarks(string $complaint): string
    {
        return match ($complaint) {
            'Body pain' => 'Advised to avoid strenuous activity, including PE classes, during the rest period.',
            'Dysmenorrhea' => 'Advised to rest. Fit to return to classes the following day.',
            'Toothache, for extraction' => 'Had a tooth extraction. Advised to rest for the day.',
            default => 'Advised to rest at home and increase fluid intake. May return to classes after the rest period.',
        };
    }

    /**
     * A PATHFIT checkup day flagged inside the coming week, plus bookings
     * for the next seven clinic days with every professional on their own
     * days (never on the event day): most confirmed, a few still under
     * review for the nurse, one declined and one cancelled. The demo patient
     * gets a confirmed dental booking with people ahead of them and one
     * booking with the physician still under review.
     *
     * @param  array<string, int>  $providers  profession => user id
     */
    private function seedUpcoming(array $providers): void
    {
        $today = Carbon::today();

        $eventDate = $today->copy()->addDays(4);
        while (! $eventDate->isTuesday()) {
            $eventDate->addDay();
        }

        if (! EventDay::whereDate('date', $eventDate)->exists()) {
            EventDay::create([
                'date' => $eventDate->toDateString(),
                'label' => 'PATHFIT medical checkup (first-year students)',
                'note' => 'Walk-in only; online booking is closed for this date.',
            ]);
        }

        $nurseId = $providers['nurse'] ?? null;
        $patients = User::where('email', 'like', 'demo.patient%@flowcast.test')->pluck('id')->all();
        $demoPatientId = User::where('email', DevUsersSeeder::PATIENT_EMAIL)->value('id');

        if (! $nurseId || $patients === [] || Appointment::where('scheduled_at', '>', $today->copy()->endOfDay())->exists()) {
            return;
        }

        $schedules = ProviderSchedule::with('user:id,profession')->where('is_active', true)->get()
            ->groupBy('day_of_week');

        $newcomerId = $this->newcomer();

        $rows = [];
        $clinicDays = 0;
        $demoDentalBooked = false;
        $demoPhysicianRequested = false;
        $newcomerRequested = false;

        for ($day = $today->copy()->addDay(); $clinicDays < 7; $day->addDay()) {
            if (! in_array($day->dayOfWeek, ClinicSetupSeeder::CLINIC_DAYS, true) || EventDay::whereDate('date', $day)->exists()) {
                continue;
            }

            $clinicDays++;

            foreach ($schedules->get($day->dayOfWeek, collect()) as $schedule) {
                $profession = $schedule->user->profession;
                $slots = $schedule->slotStarts($day);
                $taken = [];
                [$min, $max] = self::UPCOMING_PER_DAY[$profession];

                $book = function (int $patientId, int $slotIndex, string $status, ?string $reason = null) use (&$rows, &$taken, $slots, $schedule, $profession, $nurseId): void {
                    while (isset($slots[$slotIndex]) && isset($taken[$slotIndex])) {
                        $slotIndex++;
                    }

                    if (! isset($slots[$slotIndex])) {
                        return;
                    }

                    $taken[$slotIndex] = true;
                    $rows[] = $this->upcomingRow($patientId, $schedule->user_id, $nurseId, $slots[$slotIndex], $status, $reason ?? $this->reasonFor($profession));
                };

                // The demo patient's confirmed dental booking, with two
                // confirmed patients booked before them.
                if ($profession === 'dentist' && ! $demoDentalBooked && $demoPatientId) {
                    $book($patients[mt_rand(0, count($patients) - 1)], 0, 'booked');
                    $book($patients[mt_rand(0, count($patients) - 1)], 2, 'booked');
                    $book($demoPatientId, 4, 'booked', 'Tooth cavity');
                    $demoDentalBooked = true;
                }

                if ($profession === 'physician' && ! $demoPhysicianRequested && $demoPatientId) {
                    $book($demoPatientId, 1, 'pending', 'Medical clearance');
                    $demoPhysicianRequested = true;
                }

                for ($i = 0, $count = mt_rand($min, $max); $i < $count; $i++) {
                    $book($patients[mt_rand(0, count($patients) - 1)], mt_rand(0, count($slots) - 1), 'booked');
                }

                // Requests made since the nurse last reviewed: the first few
                // days each get one per professional. The first one with the
                // nurse is the newcomer's (the random pick is still drawn, so
                // every other sample row stays the same).
                if ($clinicDays <= 4) {
                    $requester = $patients[mt_rand(0, count($patients) - 1)];

                    if ($profession === 'nurse' && ! $newcomerRequested) {
                        $requester = $newcomerId;
                        $newcomerRequested = true;
                    }

                    $book($requester, mt_rand(0, count($slots) - 1), 'pending');
                }

                if ($clinicDays === 2 && $profession === 'nurse') {
                    $book($patients[mt_rand(0, count($patients) - 1)], mt_rand(0, count($slots) - 1), 'declined');
                }

                if ($clinicDays === 3 && $profession === 'dentist') {
                    $book($patients[mt_rand(0, count($patients) - 1)], mt_rand(0, count($slots) - 1), 'cancelled');
                }
            }
        }

        DB::table('appointments')->insert($rows);

        $this->command?->info(sprintf(
            'Flagged %s as an event day and added %d upcoming bookings (%d under review).',
            $eventDate->toFormattedDateString(),
            count($rows),
            count(array_filter($rows, fn (array $row) => $row['status'] === 'pending')),
        ));
    }

    /**
     * The newcomer's account (see NEWCOMER_EMAIL), with a complete health
     * record but an ID number nobody has checked yet. Fixed details, no
     * random draws, so the rest of the sample data is unaffected.
     */
    private function newcomer(): int
    {
        $user = User::firstOrNew(['email' => self::NEWCOMER_EMAIL]);
        $user->fill([
            'password' => Hash::make('password'),
            'first_name' => 'Kristine Joy',
            'middle_name' => 'Bautista',
            'last_name' => 'Manalo',
            'name' => User::joinName('Kristine Joy', 'Bautista', 'Manalo'),
            'birthdate' => '2007-05-21',
            'sex' => 'female',
            'home_address' => '12 Arellano St., Dagupan City, Pangasinan',
            'school_id' => '26-1102-457',
            'patient_type' => 'student',
            'department' => 'SOHS',
            'course' => 'BSN',
            'contact_no' => '09181234567',
        ]);
        // `role` is not mass-assignable; set it explicitly.
        $user->role = 'patient';
        $user->email_verified_at ??= now();
        $user->created_at ??= now()->subDays(2);
        $user->save();

        return $user->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function upcomingRow(int $patientId, int $providerId, int $nurseId, Carbon $slot, string $status, string $reason): array
    {
        $madeAt = now()->subMinutes(mt_rand(30, 4000));
        $reviewed = in_array($status, ['booked', 'declined'], true);
        $reviewedAt = $madeAt->copy()->addMinutes(mt_rand(20, 300))->min(now());

        return [
            'patient_id' => $patientId,
            'provider_id' => $providerId,
            'scheduled_at' => $slot->toDateTimeString(),
            'reason' => $reason,
            'status' => $status,
            'cancel_reason' => $status === 'cancelled' ? 'Schedule conflict with an exam.' : null,
            'reviewed_by_id' => $reviewed ? $nurseId : null,
            'reviewed_at' => $reviewed ? $reviewedAt->toDateTimeString() : null,
            'review_note' => $status === 'declined' ? 'The clinic is hosting a seminar at that hour. Please choose another time.' : null,
            'created_at' => $madeAt->toDateTimeString(),
            'updated_at' => ($reviewed ? $reviewedAt : $madeAt)->toDateTimeString(),
        ];
    }

    private function reasonFor(string $profession): string
    {
        $reasons = match ($profession) {
            'dentist' => self::DENTIST_COMPLAINTS,
            'physician' => self::PHYSICIAN_COMPLAINTS,
            default => self::NURSE_BOOKING_REASONS,
        };

        return $reasons[mt_rand(0, count($reasons) - 1)];
    }

    /**
     * $time on $day if the professional has nothing booked then, otherwise
     * their first free slot that day; null if the day is full.
     */
    private function freeSlot(int $providerId, Carbon $day, string $time): ?Carbon
    {
        $schedule = ProviderSchedule::where('user_id', $providerId)->where('day_of_week', $day->dayOfWeek)->first();

        if (! $schedule) {
            return null;
        }

        $held = Appointment::where('provider_id', $providerId)
            ->whereDate('scheduled_at', $day)
            ->whereIn('status', Appointment::SLOT_HOLDING_STATUSES)
            ->pluck('scheduled_at')
            ->map(fn ($at) => Carbon::parse($at)->format('H:i'))
            ->all();

        $preferred = Carbon::parse($day->toDateString().' '.$time);
        $slots = $schedule->slotStarts($day);
        usort($slots, fn (Carbon $a, Carbon $b) => abs($a->diffInMinutes($preferred, false)) <=> abs($b->diffInMinutes($preferred, false)));

        foreach ($slots as $slot) {
            if (! in_array($slot->format('H:i'), $held, true)) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * The latest day on or before $date that falls on one of $weekdays and
     * is not an event day.
     *
     * @param  array<int, int>  $weekdays
     */
    private function clinicDayOnOrBefore(Carbon $date, array $weekdays): Carbon
    {
        $day = $date->copy()->startOfDay();

        for ($i = 0; $i < 21; $i++, $day->subDay()) {
            if (in_array($day->dayOfWeek, $weekdays, true) && ! EventDay::whereDate('date', $day)->exists()) {
                break;
            }
        }

        return $day;
    }
}
