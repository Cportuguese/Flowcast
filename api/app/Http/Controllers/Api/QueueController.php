<?php

namespace App\Http\Controllers\Api;

use App\Events\QueueBoardUpdated;
use App\Events\QueueEntryUpdated;
use App\Events\QueueUpdated;
use App\Events\TicketCalled;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\ClinicSchedule;
use App\Models\QueueEntry;
use App\Models\User;
use App\Services\AppointmentNotifier;
use App\Services\AuditLogger;
use App\Services\ExpoPushNotifier;
use App\Services\Ml\WaitTimePredictor;
use App\Support\HealthRecord;
use App\Support\Realtime;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class QueueController extends Controller
{
    /** Terminal statuses: an entry in one of these has left the queue. */
    private const RESOLVED_STATUSES = ['done', 'skipped'];

    /**
     * Audit action per terminal status. Spelled out rather than derived from
     * the status so the log keeps reading 'queue.completed' for 'done'.
     */
    private const RESOLVED_AUDIT_ACTIONS = ['done' => 'queue.completed', 'skipped' => 'queue.skipped'];

    /**
     * What the appointment that brought a patient in becomes when their entry
     * resolves. A skip counts as a no-show: the patient was not there when
     * called. (A checked-in booking is settled here, never by marking it a
     * no-show by hand, which would leave its entry waiting in the line.)
     */
    private const APPOINTMENT_OUTCOMES = ['done' => 'completed', 'skipped' => 'no_show'];

    /**
     * Patient columns loaded alongside queue entries. push_token is here for
     * the "almost your turn" push in broadcastQueue(), not for display: every
     * payload is built field by field in formatEntry(), so it is never sent
     * to a client. Without it the notifier saw no token and silently skipped
     * that push for every patient.
     */
    private const PATIENT_COLUMNS = 'patient:id,name,contact_no,push_token,school_id,school_id_verified_at,department,course';

    /**
     * Call Next needs the booked time; the visit form starts from the
     * reason the patient gave when booking.
     */
    private const APPOINTMENT_COLUMNS = 'appointment:id,scheduled_at,reason';

    /**
     * A patient booked with the nurse is called at their booked time, ahead
     * of walk-ins — if they checked in no later than this after it. Anyone
     * later joins the line like a walk-in, so one late booking cannot jump
     * a morning's worth of people who came on time.
     */
    public const LATE_GRACE_MINUTES = 15;

    /** Walk-in records get a placeholder email: they never log in. */
    private const WALK_IN_EMAIL_PREFIX = 'walkin_';

    public function __construct(private readonly WaitTimePredictor $waitTimePredictor) {}

    public function checkIn(Request $request)
    {
        $user = $request->user();
        $isStaff = $user->isStaff();

        $validated = $request->validate([
            // Only patient accounts belong in the queue — staff checking in
            // another staff member's ID is always a mistake.
            'patient_id' => [
                $isStaff ? 'required' : 'prohibited',
                'integer',
                Rule::exists('users', 'id')->where('role', 'patient'),
            ],
            'appointment_id' => ['nullable', 'integer', 'exists:appointments,id'],
        ]);

        $patientId = $isStaff ? $validated['patient_id'] : $user->id;
        $today = Carbon::today();

        $alreadyActive = QueueEntry::where('patient_id', $patientId)
            ->where('queue_date', $today)
            ->whereIn('status', ['waiting', 'in_consultation'])
            ->exists();

        if ($alreadyActive) {
            return response()->json(['message' => 'This patient already has an active queue entry today.'], 422);
        }

        $appointment = null;

        if (! empty($validated['appointment_id'])) {
            $appointment = Appointment::with('provider:id,profession')
                ->where('id', $validated['appointment_id'])
                ->where('patient_id', $patientId)
                ->first();

            if (! $appointment) {
                return response()->json(['message' => 'Appointment not found for this patient.'], 422);
            }

            // Only a booking the nurse has confirmed: one still under review
            // (or declined) never reaches the queue.
            if ($appointment->status !== 'booked') {
                return response()->json(['message' => 'This appointment cannot be checked in.'], 422);
            }

            if (! $appointment->goesThroughQueue()) {
                return response()->json(['message' => 'The dentist and the school physician see you at your booked time — no queue check-in needed.'], 422);
            }

            if (! $appointment->scheduled_at->isSameDay($today)) {
                return response()->json(['message' => 'This appointment is not for today.'], 422);
            }
        }

        $entry = $this->assignQueueEntry($patientId, $appointment, $today);

        $this->broadcastQueue($today);

        return response()->json($this->formatEntry($entry), 201);
    }

    /**
     * Find a registered patient by ID number, so the nurse checks in the
     * existing record instead of creating a duplicate walk-in — and on
     * their booking with her today, if they have one, so they keep their
     * place at the booked time.
     *
     * A number the patient typed in themselves is only a claim until the
     * nurse has compared it with their UDD ID card, so she gets what she
     * needs to tell whether the person at the counter is the account
     * holder: date of birth, sex, the email it was registered with, and
     * whether the number has been checked before.
     */
    public function lookupPatient(Request $request)
    {
        $validated = $request->validate([
            'school_id' => ['required', 'string', 'max:32'],
        ]);

        // Typed at the counter without the dashes ("231057651"), a student
        // ID would otherwise find nobody and send the nurse to register a
        // duplicate.
        $patient = User::where('role', 'patient')
            ->where('school_id', HealthRecord::normalizeSchoolId($validated['school_id']))
            ->first(['id', 'name', 'email', 'school_id', 'school_id_verified_at', 'department', 'course', 'patient_type', 'birthdate', 'sex', 'created_at']);

        if (! $patient) {
            return response()->json(['patient' => null, 'in_queue' => false, 'booking_today' => null]);
        }

        // Walk-ins the nurse registered have a placeholder address, not a real one.
        $isWalkInRecord = str_starts_with($patient->email, self::WALK_IN_EMAIL_PREFIX);

        $booking = Appointment::where('patient_id', $patient->id)
            ->throughQueue()
            ->where('status', 'booked')
            ->whereDate('scheduled_at', Carbon::today())
            ->orderBy('scheduled_at')
            ->first(['id', 'scheduled_at']);

        return response()->json([
            'booking_today' => $booking ? [
                'id' => $booking->id,
                'scheduled_at' => $booking->scheduled_at->toIso8601String(),
            ] : null,
            'patient' => [
                'id' => $patient->id,
                'name' => $patient->name,
                'school_id' => $patient->school_id,
                'id_verified' => $patient->hasVerifiedSchoolId(),
                'department' => $patient->department,
                'course' => $patient->course,
                'patient_type' => $patient->patient_type,
                'birthdate' => $patient->birthdate?->toDateString(),
                'sex' => $patient->sex,
                'email' => $isWalkInRecord ? null : $patient->email,
                'registered_on' => $patient->created_at?->toDateString(),
            ],
            'in_queue' => QueueEntry::where('patient_id', $patient->id)
                ->where('queue_date', Carbon::today())
                ->whereIn('status', ['waiting', 'in_consultation'])
                ->exists(),
        ]);
    }

    /**
     * Register a walk-in patient who has no account yet and check them
     * straight into today's queue. The nurse records just enough to count
     * them by department; the patient can complete their record later.
     */
    public function walkIn(Request $request)
    {
        if (is_string($request->input('school_id'))) {
            $request->merge(['school_id' => HealthRecord::normalizeSchoolId($request->input('school_id'))]);
        }

        $validated = $request->validate(
            HealthRecord::walkInRules($request->all()),
            [...HealthRecord::MESSAGES, 'school_id.unique' => 'This ID number is already registered — use Find to check that patient in.'],
            HealthRecord::ATTRIBUTE_NAMES,
        );

        $patient = new User([
            ...HealthRecord::attributes($validated),
            'email' => self::WALK_IN_EMAIL_PREFIX.Str::uuid()->toString().'@flowcast.local',
            'password' => Hash::make(Str::random(32)),
        ]);
        // `role` is deliberately not mass-assignable; set it explicitly.
        $patient->role = 'patient';
        // The nurse took the ID number down at the counter, from the person
        // and their card, so it starts out checked.
        if ($patient->school_id !== null) {
            $patient->markSchoolIdVerified($request->user());
        }
        $patient->save();

        $today = Carbon::today();

        $entry = $this->assignQueueEntry($patient->id, null, $today);

        app(AuditLogger::class)->log($request->user()->id, 'queue.walk_in', $entry, [
            'name' => $patient->name,
            'department' => $patient->department,
            'queue_number' => $entry->queue_number,
        ]);

        $this->broadcastQueue($today);

        return response()->json($this->formatEntry($entry), 201);
    }

    private function assignQueueEntry(int $patientId, ?Appointment $appointment, Carbon $today): QueueEntry
    {
        $now = Carbon::now();
        $waiting = QueueEntry::where('queue_date', $today)
            ->where('status', 'waiting')
            ->with(self::APPOINTMENT_COLUMNS)
            ->get();

        // A newcomer joins the back of the line — unless they are a booked
        // patient arriving on time, who goes behind only the bookings due
        // before theirs.
        $scheduledAt = $appointment?->scheduled_at;
        $peopleAhead = $scheduledAt !== null && $scheduledAt->lte($now) && $now->lte($scheduledAt->copy()->addMinutes(self::LATE_GRACE_MINUTES))
            ? $waiting->filter(fn (QueueEntry $entry) => $this->isDueBooking($entry, $now) && $entry->appointment->scheduled_at->lte($scheduledAt))->count()
            : $waiting->count();

        $estimate = $this->estimatedWait($today, $peopleAhead, $appointment !== null);

        $entry = DB::transaction(function () use ($patientId, $appointment, $today, $estimate) {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $nextNumber = (int) QueueEntry::where('queue_date', $today)->max('queue_number') + 1;

                try {
                    return QueueEntry::create([
                        'queue_number' => $nextNumber,
                        'queue_date' => $today,
                        'patient_id' => $patientId,
                        'source' => $appointment ? 'appointment' : 'walk_in',
                        'status' => 'waiting',
                        'appointment_id' => $appointment?->id,
                        'joined_at' => now(),
                        // Recorded at arrival so predicted-vs-actual stays
                        // measurable on live data, not just on a holdout.
                        'predicted_wait_minutes' => $estimate['minutes'],
                        'prediction_model_version' => $estimate['model_version'],
                    ]);
                } catch (QueryException $e) {
                    if ($attempt === 4 || $e->getCode() !== '23000') {
                        throw $e;
                    }
                }
            }
        });

        if ($appointment) {
            $appointment->update(['status' => 'checked_in']);
        }

        $this->waitTimePredictor->refresh();

        return $entry;
    }

    public function callNext(Request $request)
    {
        $today = Carbon::today();

        $waiting = QueueEntry::where('queue_date', $today)
            ->where('status', 'waiting')
            ->with(self::APPOINTMENT_COLUMNS)
            ->get();

        $entry = $this->callOrder($waiting, Carbon::now())->first();

        if (! $entry) {
            return response()->json(['message' => 'No one is waiting in the queue.'], 404);
        }

        $entry->update([
            'status' => 'in_consultation',
            'called_at' => now(),
            'handled_by_id' => $request->user()->id,
        ]);

        app(ExpoPushNotifier::class)->send(
            $entry->patient,
            "You're up!",
            'Please proceed to the counter now.',
            ['queue_entry_id' => $entry->id],
        );

        $this->safeBroadcast(new TicketCalled($today->toDateString(), $entry->queue_number, $entry->patient_id));

        app(AuditLogger::class)->log($request->user()->id, 'queue.called', $entry, [
            'queue_number' => $entry->queue_number,
            'patient_id' => $entry->patient_id,
        ]);

        $this->broadcastQueue($today);

        return response()->json($this->formatEntry($entry->fresh()));
    }

    public function complete(Request $request, QueueEntry $queueEntry)
    {
        return $this->resolve($request, $queueEntry, 'done');
    }

    public function skip(Request $request, QueueEntry $queueEntry)
    {
        return $this->resolve($request, $queueEntry, 'skipped');
    }

    /**
     * The two ways an entry leaves the queue. Both are one-way: an entry that
     * has already been resolved is refused rather than resolved again, so a
     * double-click cannot overwrite a completion with a skip.
     *
     * Only 'done' stamps completed_at — it is what consult duration is
     * measured from, and a skipped patient was never consulted.
     */
    private function resolve(Request $request, QueueEntry $queueEntry, string $status)
    {
        if (in_array($queueEntry->status, self::RESOLVED_STATUSES, true)) {
            return response()->json(['message' => 'This queue entry has already been resolved.'], 422);
        }

        $settledAppointment = DB::transaction(function () use ($queueEntry, $status) {
            $queueEntry->update([
                'status' => $status,
                ...$status === 'done' ? ['completed_at' => now()] : [],
            ]);

            // The appointment finishes with its queue entry. Without this it
            // stayed "checked in" for good, so the patient's app listed a
            // finished or skipped visit under Upcoming. Only a still-checked-in
            // appointment moves; one already cancelled or marked a no-show
            // keeps that status.
            return $queueEntry->appointment_id !== null
                && Appointment::whereKey($queueEntry->appointment_id)
                    ->where('status', 'checked_in')
                    ->update(['status' => self::APPOINTMENT_OUTCOMES[$status]]) > 0;
        });

        // Refresh the patient's Appointments screen, and the "people ahead"
        // of everyone booked after them, like every other booking change.
        if ($settledAppointment && ($appointment = Appointment::find($queueEntry->appointment_id))) {
            $notifier = app(AppointmentNotifier::class);
            $notifier->changed($appointment);
            $notifier->lineMoved($appointment);
        }

        app(AuditLogger::class)->log($request->user()->id, self::RESOLVED_AUDIT_ACTIONS[$status], $queueEntry, [
            'queue_number' => $queueEntry->queue_number,
        ]);

        $this->broadcastQueue($queueEntry->queue_date);

        return response()->json($this->formatEntry($queueEntry));
    }

    public function today()
    {
        $today = Carbon::today();

        return response()->json([
            'date' => $today->toDateString(),
            'entries' => $this->entriesFor($today)->map(fn (QueueEntry $entry) => $this->formatEntry($entry))->values()->all(),
        ]);
    }

    /**
     * Same-shaped history for a past date, admin-only, for basic reporting.
     */
    public function history(string $date)
    {
        $day = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();

        return response()->json([
            'date' => $day->toDateString(),
            'entries' => $this->entriesFor($day)->map(fn (QueueEntry $entry) => $this->formatEntry($entry))->values()->all(),
        ]);
    }

    /**
     * Public, unauthenticated board for a waiting-room TV display.
     * No patient data — numbers and statuses only, plus opening hours so
     * the board can show a "closed" state.
     */
    public function boardToday()
    {
        $today = Carbon::today();

        return response()->json([
            'date' => $today->toDateString(),
            'entries' => $this->boardEntries($this->entriesFor($today), Carbon::now()),
            'hours' => $this->boardHours(Carbon::now()),
        ]);
    }

    /**
     * The board's numbers and statuses only. Waiting numbers come in the
     * order they will be called — a booked patient whose time has come
     * first — so the board's "Up next" matches Call Next; the rest follow
     * by number.
     *
     * @param  Collection<int, QueueEntry>  $entries
     * @return array<int, array{queue_number: int, status: string}>
     */
    private function boardEntries(Collection $entries, Carbon $now): array
    {
        return $this->callOrder($entries->where('status', 'waiting'), $now)
            ->concat($entries->where('status', '!=', 'waiting'))
            ->map(fn (QueueEntry $entry) => [
                'queue_number' => $entry->queue_number,
                'status' => $entry->status,
            ])
            ->values()
            ->all();
    }

    /**
     * Whether the clinic is open right now, today's hours, and the next
     * opening within the coming week (today's, if it hasn't started yet).
     *
     * @return array{is_open: bool, today: array{start_time: string, end_time: string}|null, next_opening: array{date: string, day: string, start_time: string}|null}
     */
    private function boardHours(Carbon $now): array
    {
        $schedules = ClinicSchedule::where('is_active', true)->get()->keyBy('day_of_week');
        $todaySchedule = $schedules->get($now->dayOfWeek);
        $clock = $now->format('H:i:s');

        $isOpen = $todaySchedule
            && $clock >= $this->toTime($todaySchedule->start_time)
            && $clock < $this->toTime($todaySchedule->end_time);

        $nextOpening = null;
        for ($offset = 0; $offset < 7; $offset++) {
            $day = $now->copy()->addDays($offset);
            $schedule = $schedules->get($day->dayOfWeek);
            if (! $schedule) {
                continue;
            }
            if ($offset === 0 && $clock >= $this->toTime($schedule->start_time)) {
                continue; // today's session already started (or ended)
            }
            $nextOpening = [
                'date' => $day->toDateString(),
                'day' => $day->format('l'),
                'start_time' => substr($this->toTime($schedule->start_time), 0, 5),
            ];
            break;
        }

        return [
            'is_open' => (bool) $isOpen,
            'today' => $todaySchedule ? [
                'start_time' => substr($this->toTime($todaySchedule->start_time), 0, 5),
                'end_time' => substr($this->toTime($todaySchedule->end_time), 0, 5),
            ] : null,
            'next_opening' => $nextOpening,
        ];
    }

    /** Normalise "08:00" / "08:00:00" to "HH:MM:SS" for string comparison. */
    private function toTime(string $time): string
    {
        return strlen($time) === 5 ? $time.':00' : $time;
    }

    public function mine(Request $request)
    {
        $today = Carbon::today();

        $entry = QueueEntry::where('patient_id', $request->user()->id)
            ->where('queue_date', $today)
            ->whereIn('status', ['waiting', 'in_consultation'])
            ->first();

        if (! $entry) {
            return response()->json(['date' => $today->toDateString(), 'entry' => null]);
        }

        $now = Carbon::now();
        $order = $this->callOrder($this->entriesFor($today)->where('status', 'waiting'), $now)->pluck('id');
        $position = $order->search($entry->id);

        // Someone already called is not waiting behind anyone.
        $peopleAhead = $position === false ? 0 : $position;
        $estimate = $this->estimatedWait($today, $peopleAhead, $entry->source === 'appointment');

        return response()->json([
            'date' => $today->toDateString(),
            'entry' => $this->formatEntry($entry),
            'position' => $peopleAhead + 1,
            'people_ahead' => $peopleAhead,
            'estimated_wait_minutes' => $estimate['minutes'],
            'estimated_wait_low' => $estimate['low'],
            'estimated_wait_high' => $estimate['high'],
            'estimated_call_at' => $now->copy()->addMinutes($estimate['minutes'])->toIso8601String(),
            'estimate_source' => $estimate['source'],
        ]);
    }

    /**
     * Waiting entries in the order Call Next will take them right now:
     * booked patients whose time has come (and who arrived on time) first,
     * earliest booking first; then everyone else by queue number.
     *
     * Call Next, "people ahead of you" and the near-turn push all read this
     * one ordering, so what a patient is told always matches who is called.
     *
     * @param  SupportCollection<int, QueueEntry>  $waiting
     * @return SupportCollection<int, QueueEntry>
     */
    private function callOrder(SupportCollection $waiting, Carbon $now): SupportCollection
    {
        [$due, $rest] = $waiting->partition(fn (QueueEntry $entry) => $this->isDueBooking($entry, $now));

        return $due
            ->sortBy([
                fn (QueueEntry $a, QueueEntry $b) => $a->appointment->scheduled_at <=> $b->appointment->scheduled_at,
                fn (QueueEntry $a, QueueEntry $b) => $a->queue_number <=> $b->queue_number,
            ])
            ->concat($rest->sortBy('queue_number'))
            ->values();
    }

    /** A booked patient, checked in on time, whose booked time has come. */
    private function isDueBooking(QueueEntry $entry, Carbon $now): bool
    {
        $scheduledAt = $entry->appointment?->scheduled_at;

        return $scheduledAt !== null
            && $scheduledAt->lte($now)
            && $entry->joined_at->lte($scheduledAt->copy()->addMinutes(self::LATE_GRACE_MINUTES));
    }

    /**
     * Delegates to the trained wait-time model, which falls back to the
     * original people-ahead x mean-consult heuristic when no model is
     * active or the active one was fitted on too little history.
     *
     * @return array{minutes: int, low: int, high: int, source: string, model_version: int|null}
     */
    private function estimatedWait(Carbon $date, int $peopleAhead, bool $isAppointment = false): array
    {
        return $this->waitTimePredictor->predict($date, $peopleAhead, $isAppointment);
    }

    /**
     * @return Collection<int, QueueEntry>
     */
    private function entriesFor(Carbon $date): Collection
    {
        return QueueEntry::where('queue_date', $date)
            ->with([self::PATIENT_COLUMNS, self::APPOINTMENT_COLUMNS])
            ->orderBy('queue_number')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatEntry(QueueEntry $entry): array
    {
        $entry->loadMissing([self::PATIENT_COLUMNS, self::APPOINTMENT_COLUMNS]);

        return [
            'id' => $entry->id,
            'queue_number' => $entry->queue_number,
            'status' => $entry->status,
            'source' => $entry->source,
            'appointment_id' => $entry->appointment_id,
            'booked_for' => $entry->appointment?->scheduled_at?->toIso8601String(),
            'booking_reason' => $entry->appointment?->reason,
            'patient' => [
                'id' => $entry->patient->id,
                'name' => $entry->patient->name,
                'contact_no' => $entry->patient->contact_no,
                'school_id' => $entry->patient->school_id,
                'id_verified' => $entry->patient->hasVerifiedSchoolId(),
                'department' => $entry->patient->department,
                'course' => $entry->patient->course,
            ],
            'joined_at' => $entry->joined_at?->toIso8601String(),
            'called_at' => $entry->called_at?->toIso8601String(),
            'completed_at' => $entry->completed_at?->toIso8601String(),
        ];
    }

    /**
     * Broadcast the full board to staff, and each patient their own sanitized
     * entry on their private channel. Also fires the one-shot "almost your
     * turn" push once a waiting patient has two or fewer people ahead.
     */
    private function broadcastQueue(Carbon $date): void
    {
        // Statuses have just changed; the estimates below must see them.
        $this->waitTimePredictor->refresh();

        $entries = $this->entriesFor($date);

        $this->safeBroadcast(new QueueUpdated(
            $date->toDateString(),
            $entries->map(fn (QueueEntry $entry) => $this->formatEntry($entry))->values()->all(),
        ));

        $now = Carbon::now();

        $this->safeBroadcast(new QueueBoardUpdated($date->toDateString(), $this->boardEntries($entries, $now)));

        // Position in the real call order, booked patients due now included.
        $positions = $this->callOrder($entries->where('status', 'waiting'), $now)->pluck('id')->flip();

        foreach ($entries as $entry) {
            $isWaiting = $entry->status === 'waiting';
            $peopleAhead = $isWaiting ? (int) $positions->get($entry->id, 0) : null;
            $estimate = $isWaiting
                ? $this->estimatedWait($date, $peopleAhead, $entry->source === 'appointment')
                : null;

            $this->safeBroadcast(new QueueEntryUpdated($entry->patient_id, [
                'date' => $date->toDateString(),
                'entry' => $this->formatEntry($entry),
                'position' => $isWaiting ? $peopleAhead + 1 : null,
                'people_ahead' => $peopleAhead,
                'estimated_wait_minutes' => $estimate['minutes'] ?? null,
                'estimated_wait_low' => $estimate['low'] ?? null,
                'estimated_wait_high' => $estimate['high'] ?? null,
                'estimated_call_at' => $estimate ? $now->copy()->addMinutes($estimate['minutes'])->toIso8601String() : null,
                'estimate_source' => $estimate['source'] ?? null,
            ]));

            if ($isWaiting && $peopleAhead <= 2 && ! $entry->near_call_notified_at) {
                app(ExpoPushNotifier::class)->send(
                    $entry->patient,
                    'Almost your turn',
                    'You are almost up — please head to the clinic now.',
                    ['queue_entry_id' => $entry->id],
                );
                $entry->update(['near_call_notified_at' => now()]);
            }
        }
    }

    /**
     * Realtime updates are best-effort: a down Reverb server must never
     * fail (or stall) the check-in/call/complete request that already hit
     * the database. See Realtime for how a down server is skipped.
     */
    private function safeBroadcast(object $event): void
    {
        Realtime::broadcast($event);
    }
}
