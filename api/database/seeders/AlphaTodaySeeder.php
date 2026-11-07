<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\ClinicSchedule;
use App\Models\EventDay;
use App\Models\Medicine;
use App\Models\ProviderSchedule;
use App\Models\QueueEntry;
use App\Models\User;
use App\Models\VisitRecord;
use App\Services\InventoryService;
use Database\Seeders\Concerns\BackdatesRecords;
use Database\Seeders\Concerns\SamplesClinicalCases;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Today, ready for a live demo:
 *
 * - Nurse Portia's morning already under way (three patients served, one
 *   skipped, one in consultation, three waiting)
 * - a confirmed booking with her late today for the demo patient, so
 *   "Check in for my appointment" can be shown from the phone
 * - the dentist's or the physician's day, if today is theirs: earlier
 *   patients seen, later ones still to come
 * - a few bookings under review, so the nurse has something to confirm
 *
 * Safe to run every demo morning:
 *
 *   php artisan db:seed --class=AlphaTodaySeeder
 *
 * It adds each part only when today does not have it yet. SAMPLE DATA, not
 * real patients. Seeded entries carry no predicted wait, so the live
 * predicted-vs-actual chart only ever shows real check-ins.
 */
class AlphaTodaySeeder extends Seeder
{
    use BackdatesRecords;
    use SamplesClinicalCases;

    /**
     * The sample morning, oldest first, as minutes before "now":
     * [status, source, joined, called, finished].
     *
     * @var array<int, array{0: string, 1: string, 2: int, 3: int|null, 4: int|null}>
     */
    private const MORNING = [
        ['done', 'walk_in', 78, 74, 63],
        ['done', 'appointment', 70, 62, 50],
        ['done', 'walk_in', 58, 49, 38],
        ['skipped', 'walk_in', 52, 36, null],
        ['in_consultation', 'walk_in', 40, 8, null],
        ['waiting', 'appointment', 24, null, null],
        ['waiting', 'walk_in', 15, null, null],
        ['waiting', 'walk_in', 6, null, null],
    ];

    /** Keep at least this many bookings under review for the nurse to act on. */
    private const BOOKINGS_TO_REVIEW = 3;

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('AlphaTodaySeeder writes sample patients. Refusing to run in production.');

            return;
        }

        $today = Carbon::today();
        $clinic = ClinicSchedule::where('day_of_week', $today->dayOfWeek)->where('is_active', true)->first();

        if (! $clinic) {
            $this->command?->warn('The clinic has no opening hours today, so no sample queue was added.');

            return;
        }

        mt_srand((int) $today->format('Ymd'));

        $nurse = User::providers()->where('profession', 'nurse')->first();
        $demoPatient = User::where('email', DevUsersSeeder::PATIENT_EMAIL)->first();

        if (! $nurse) {
            $this->command?->warn('No nurse account; run DevUsersSeeder first.');

            return;
        }

        $this->seedQueue($today, $clinic, $nurse, $demoPatient);

        if (EventDay::whereDate('date', $today)->exists()) {
            return; // booking is closed on event days
        }

        $this->seedNurseBookings($today, $nurse, $demoPatient);

        foreach (User::providers()->whereIn('profession', ['dentist', 'physician'])->get() as $provider) {
            $this->seedBookedDay($today, $provider, $nurse);
        }

        $this->keepBookingsToReview($today, $nurse);
    }

    private function seedQueue(Carbon $today, ClinicSchedule $clinic, User $nurse, ?User $demoPatient): void
    {
        if (QueueEntry::whereDate('queue_date', $today)->exists()) {
            $this->command?->info('Today already has a queue; it was left as it is.');

            return;
        }

        $patientIds = $this->samplePatientIds($demoPatient);

        if (count($patientIds) < count(self::MORNING)) {
            $this->command?->warn('Not enough sample patients; no queue added.');

            return;
        }

        // Pick distinct patients reproducibly for the day.
        $picked = [];
        while (count($picked) < count(self::MORNING)) {
            $picked[$patientIds[mt_rand(0, count($patientIds) - 1)]] = true;
        }
        $picked = array_keys($picked);

        $open = Carbon::parse($today->toDateString().' '.$clinic->start_time);
        $close = Carbon::parse($today->toDateString().' '.$clinic->end_time);
        // The sample morning ends "now" — but at least 80 minutes after
        // opening, so seeding before the clinic opens still keeps every
        // time inside opening hours, and never after closing.
        $anchor = now()->max($open->copy()->addMinutes(80))->min($close->copy()->subMinutes(5));
        $slots = $this->nurseSlots($nurse, $today);

        foreach (self::MORNING as $index => [$status, $source, $joinedAgo, $calledAgo, $finishedAgo]) {
            $patientId = $picked[$index];
            $joined = $anchor->copy()->subMinutes($joinedAgo);
            $called = $calledAgo === null ? null : $anchor->copy()->subMinutes($calledAgo);
            $finished = $finishedAgo === null ? null : $anchor->copy()->subMinutes($finishedAgo);

            $appointment = $source === 'appointment' && $slots !== []
                ? $this->createAt(Appointment::class, [
                    'patient_id' => $patientId,
                    'provider_id' => $nurse->id,
                    'scheduled_at' => $this->slotAtOrBefore($slots, $joined),
                    'reason' => self::NURSE_BOOKING_REASONS[$index % count(self::NURSE_BOOKING_REASONS)],
                    'status' => $status === 'done' ? 'completed' : 'checked_in',
                    'reviewed_by_id' => $nurse->id,
                    'reviewed_at' => $today->copy()->subDays(2)->setTime(20, 10),
                ], $today->copy()->subDays(2)->setTime(18, 30))
                : null;

            $entry = $this->createAt(QueueEntry::class, [
                'queue_number' => $index + 1,
                'queue_date' => $today,
                'patient_id' => $patientId,
                'source' => $appointment ? 'appointment' : 'walk_in',
                'status' => $status,
                'appointment_id' => $appointment?->id,
                'handled_by_id' => $called ? $nurse->id : null,
                'joined_at' => $joined,
                'called_at' => $called,
                'completed_at' => $finished,
            ], $joined);

            if ($status === 'done') {
                $complaint = $appointment ? $appointment->reason : self::NURSE_COMPLAINTS[mt_rand(0, count(self::NURSE_COMPLAINTS) - 1)];
                $this->recordVisit($complaint, $nurse, $finished, ['queue_entry_id' => $entry->id, 'patient_id' => $entry->patient_id]);
            }
        }

        $this->command?->info('Added today\'s sample queue: 3 served, 1 skipped, 1 in consultation, 3 waiting.');
    }

    /**
     * The demo patient's confirmed booking with the nurse at her last slot
     * today (unless they already have one with her today), and two
     * afternoon bookings for others.
     */
    private function seedNurseBookings(Carbon $today, User $nurse, ?User $demoPatient): void
    {
        $slots = $this->nurseSlots($nurse, $today);

        if ($slots === []) {
            return;
        }

        if ($demoPatient && ! Appointment::where('patient_id', $demoPatient->id)->where('provider_id', $nurse->id)->whereDate('scheduled_at', $today)->exists()) {
            $this->confirmedBooking($demoPatient->id, $nurse, $nurse, end($slots), 'Follow-up check-up', $today->copy()->subDays(2)->setTime(19, 40));

            $this->command?->info('Booked the demo patient with Nurse Portia for '.end($slots)->format('g:i A').' today.');
        }

        $othersBooked = Appointment::where('provider_id', $nurse->id)
            ->whereDate('scheduled_at', $today)
            ->where('status', 'booked')
            ->when($demoPatient, fn ($query) => $query->where('patient_id', '!=', $demoPatient->id))
            ->count();

        if ($othersBooked >= 2) {
            return;
        }

        $patientIds = $this->samplePatientIds($demoPatient);

        foreach ([0.7, 0.85] as $offset => $share) {
            if ($patientIds === []) {
                break;
            }

            $this->confirmedBooking(
                $patientIds[mt_rand(0, count($patientIds) - 1)],
                $nurse,
                $nurse,
                $slots[(int) floor((count($slots) - 1) * $share)],
                self::NURSE_BOOKING_REASONS[($offset + 1) % count(self::NURSE_BOOKING_REASONS)],
                $today->copy()->subDay()->setTime(20, 15),
            );
        }
    }

    /**
     * If today is the dentist's (or the physician's) day: five confirmed
     * patients spread over their hours. Those whose time has passed were
     * seen (with a visit record), or in one case did not come; the rest
     * are still to be seen.
     */
    private function seedBookedDay(Carbon $today, User $provider, User $nurse): void
    {
        $schedule = ProviderSchedule::where('user_id', $provider->id)
            ->where('day_of_week', $today->dayOfWeek)
            ->where('is_active', true)
            ->first();

        if (! $schedule || Appointment::where('provider_id', $provider->id)->whereDate('scheduled_at', $today)->exists()) {
            return;
        }

        $slots = $schedule->slotStarts($today);
        $patientIds = $this->samplePatientIds(null);
        $count = min(5, count($slots));
        $complaints = $provider->profession === 'dentist' ? self::DENTIST_COMPLAINTS : self::PHYSICIAN_COMPLAINTS;
        $missedOne = false;

        for ($i = 0; $i < $count; $i++) {
            $slot = $slots[(int) floor($i * count($slots) / $count)];
            $reason = $complaints[mt_rand(0, count($complaints) - 1)];
            $patientId = $patientIds[mt_rand(0, count($patientIds) - 1)];
            $booking = $this->confirmedBooking($patientId, $provider, $nurse, $slot, $reason, $today->copy()->subDays(3)->setTime(20, 0));

            if ($slot->copy()->addMinutes($schedule->slot_minutes)->isFuture()) {
                continue;
            }

            if (! $missedOne && $i === 1) {
                $booking->update(['status' => 'no_show']);
                $missedOne = true;

                continue;
            }

            $this->recordVisit($reason, $provider, $slot->copy()->addMinutes($schedule->slot_minutes - 5), ['appointment_id' => $booking->id, 'patient_id' => $patientId]);
            $booking->update(['status' => 'completed']);
        }

        $this->command?->info("Added {$count} of today's bookings for {$provider->display_name}.");
    }

    /**
     * Bookings get reviewed during a demo; top the list back up so there is
     * always something for the nurse to confirm or decline.
     */
    private function keepBookingsToReview(Carbon $today, User $nurse): void
    {
        $waiting = Appointment::where('status', 'pending')->where('scheduled_at', '>', now())->count();
        $patientIds = $this->samplePatientIds(null);

        if ($waiting >= self::BOOKINGS_TO_REVIEW || $patientIds === []) {
            return;
        }

        $schedules = ProviderSchedule::with('user')->where('is_active', true)->get();
        $added = 0;

        for ($day = $today->copy()->addDay(); $added < self::BOOKINGS_TO_REVIEW - $waiting && $day->lte($today->copy()->addDays(14)); $day->addDay()) {
            if (EventDay::whereDate('date', $day)->exists()) {
                continue;
            }

            foreach ($schedules->where('day_of_week', $day->dayOfWeek) as $schedule) {
                $free = array_values(array_filter(
                    $schedule->slotStarts($day),
                    fn (Carbon $slot) => ! Appointment::where('provider_id', $schedule->user_id)
                        ->where('scheduled_at', $slot)
                        ->whereIn('status', Appointment::SLOT_HOLDING_STATUSES)
                        ->exists(),
                ));

                if ($free === [] || $added >= self::BOOKINGS_TO_REVIEW - $waiting) {
                    continue;
                }

                $reasons = match ($schedule->user->profession) {
                    'dentist' => self::DENTIST_COMPLAINTS,
                    'physician' => self::PHYSICIAN_COMPLAINTS,
                    default => self::NURSE_BOOKING_REASONS,
                };

                $this->createAt(Appointment::class, [
                    'patient_id' => $patientIds[mt_rand(0, count($patientIds) - 1)],
                    'provider_id' => $schedule->user_id,
                    'scheduled_at' => $free[mt_rand(0, count($free) - 1)],
                    'reason' => $reasons[mt_rand(0, count($reasons) - 1)],
                    'status' => 'pending',
                ], now()->subMinutes(mt_rand(10, 600)));
                $added++;
            }
        }

        if ($added > 0) {
            $this->command?->info("Added {$added} booking(s) waiting for the nurse's review.");
        }
    }

    private function confirmedBooking(int $patientId, User $provider, User $nurse, Carbon $slot, string $reason, Carbon $madeAt): Appointment
    {
        return $this->createAt(Appointment::class, [
            'patient_id' => $patientId,
            'provider_id' => $provider->id,
            'scheduled_at' => $slot,
            'reason' => $reason,
            'status' => 'booked',
            'reviewed_by_id' => $nurse->id,
            'reviewed_at' => $madeAt->copy()->addHour(),
        ], $madeAt);
    }

    /**
     * The visit record for a sample patient who was seen, with any
     * medicines stocked out through the real inventory service (FIFO,
     * logged).
     *
     * @param  array{queue_entry_id?: int, appointment_id?: int, patient_id: int}  $source
     */
    private function recordVisit(string $complaint, User $seenBy, Carbon $at, array $source): void
    {
        $case = $this->caseFor($complaint);

        $visit = $this->createAt(VisitRecord::class, [
            ...$source,
            'created_by_id' => $seenBy->id,
            'complaint' => $complaint,
            'intervention' => $case['intervention'],
            'remarks' => $case['remarks'],
            'referred_to' => $case['referral'],
            ...$this->vitalsFor($case['febrile'], $complaint),
        ], $at);

        foreach ($case['medicines'] as $name => $quantity) {
            $medicine = Medicine::where('name', $name)->first();

            if (! $medicine) {
                continue;
            }

            try {
                $logs = app(InventoryService::class)->stockOut($medicine, $quantity, $seenBy->id, $visit, 'Dispensed during visit');
            } catch (ValidationException) {
                continue; // not enough stock left for the sample; skip it
            }

            foreach ($logs as $log) {
                $log->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
            }
        }
    }

    /**
     * @return array<int, Carbon>
     */
    private function nurseSlots(User $nurse, Carbon $today): array
    {
        return ProviderSchedule::where('user_id', $nurse->id)
            ->where('day_of_week', $today->dayOfWeek)
            ->where('is_active', true)
            ->first()
            ?->slotStarts($today) ?? [];
    }

    /**
     * Sample patients other than the demo patient, never walk-in shells,
     * and never the newcomer, who has not been to the clinic yet.
     *
     * @return array<int, int>
     */
    private function samplePatientIds(?User $demoPatient): array
    {
        return User::where('role', 'patient')
            ->when($demoPatient, fn ($query) => $query->whereKeyNot($demoPatient->id))
            ->whereNotIn('email', [DevUsersSeeder::PATIENT_EMAIL, AlphaSampleDataSeeder::NEWCOMER_EMAIL])
            ->where('email', 'not like', 'walkin_%')
            ->pluck('id')
            ->all();
    }

    /**
     * @param  array<int, Carbon>  $slots
     */
    private function slotAtOrBefore(array $slots, Carbon $time): Carbon
    {
        $chosen = $slots[0];

        foreach ($slots as $slot) {
            if ($slot->lte($time)) {
                $chosen = $slot;
            }
        }

        return $chosen->copy();
    }
}
