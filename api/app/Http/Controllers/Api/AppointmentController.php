<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\EventDay;
use App\Models\ProviderSchedule;
use App\Models\User;
use App\Services\AppointmentNotifier;
use App\Services\AuditLogger;
use App\Services\BookingOtp;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Booking with one of the clinic's professionals.
 *
 * A patient picks the professional, a slot and a reason, confirms their
 * health record and types the code emailed to them. The booking then waits
 * "under review" (`pending`) until the head nurse confirms (`booked`) or
 * declines it. Confirmed bookings with the nurse are seen through her queue
 * (with priority at the booked time); the dentist's and the physician's are
 * seen at the booked time, straight from their schedule.
 */
class AppointmentController extends Controller
{
    /** How far ahead a patient may book. */
    private const BOOKING_HORIZON_DAYS = 60;

    /** Patient columns a staff list shows: who they are and how to reach them. */
    private const PATIENT_COLUMNS = 'patient:id,name,email,contact_no,school_id,school_id_verified_at,department,course,sex,birthdate,patient_type';

    private const PROVIDER_COLUMNS = 'provider:id,name,role,profession';

    public function __construct(
        private readonly BookingOtp $otp,
        private readonly AppointmentNotifier $notifier,
    ) {}

    public function availableSlots(Request $request)
    {
        $validated = $request->validate([
            'provider_id' => ['required', 'integer', $this->bookableProvider()],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $date = Carbon::createFromFormat('Y-m-d', $validated['date'])->startOfDay();
        $response = ['date' => $date->toDateString(), 'provider_id' => (int) $validated['provider_id']];

        $eventDay = EventDay::whereDate('date', $date)->first();

        if ($eventDay) {
            return response()->json([...$response, 'is_event_day' => true, 'event' => $eventDay, 'slots' => []]);
        }

        $schedule = $this->scheduleFor((int) $validated['provider_id'], $date);

        return response()->json([
            ...$response,
            'is_event_day' => false,
            'slots' => $schedule ? $this->buildSlots($schedule, $date) : [],
        ]);
    }

    /**
     * A month at a glance for one professional, for the booking calendar:
     * each day is `open` (with how many places are free), `full`, `closed`
     * (not one of their days), `event` (the clinic is not taking bookings),
     * `past`, or `too_far` (beyond the booking horizon).
     */
    public function calendar(Request $request)
    {
        $validated = $request->validate([
            'provider_id' => ['required', 'integer', $this->bookableProvider()],
            'month' => ['required', 'date_format:Y-m'],
        ]);

        $providerId = (int) $validated['provider_id'];
        $start = Carbon::createFromFormat('!Y-m', $validated['month'])->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $now = Carbon::now();
        $lastBookable = $now->copy()->addDays(self::BOOKING_HORIZON_DAYS)->endOfDay();

        $schedules = ProviderSchedule::where('user_id', $providerId)->where('is_active', true)->get()->keyBy('day_of_week');
        $events = EventDay::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn (EventDay $event) => $event->date->toDateString());
        $held = Appointment::where('provider_id', $providerId)
            ->whereBetween('scheduled_at', [$start, $end->copy()->endOfDay()])
            ->whereIn('status', Appointment::SLOT_HOLDING_STATUSES)
            ->get(['scheduled_at'])
            ->countBy(fn (Appointment $appointment) => $appointment->scheduled_at->format('Y-m-d H:i'));

        $days = [];

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $date = $day->toDateString();
            $schedule = $schedules->get($day->dayOfWeek);
            $free = 0;

            $status = match (true) {
                $day->lt($now->copy()->startOfDay()) => 'past',
                $day->gt($lastBookable) => 'too_far',
                $events->has($date) => 'event',
                $schedule === null => 'closed',
                default => null,
            };

            if ($status === null) {
                $slotsLeft = 0;

                foreach ($schedule->slotStarts($day) as $slot) {
                    if ($slot->greaterThan($now)) {
                        $slotsLeft++;
                        $free += max($schedule->capacity - $held->get($slot->format('Y-m-d H:i'), 0), 0);
                    }
                }

                // Late in the day today's times have simply run out, which is
                // not the same as other patients having taken them all.
                $status = match (true) {
                    $free > 0 => 'open',
                    $slotsLeft === 0 => 'past',
                    default => 'full',
                };
            }

            $days[] = [
                'date' => $date,
                'status' => $status,
                'free_slots' => $free,
                'event' => $status === 'event' ? $events->get($date)->label : null,
            ];
        }

        return response()->json([
            'month' => $start->format('Y-m'),
            'provider_id' => $providerId,
            'days' => $days,
        ]);
    }

    /**
     * Step one of booking: check the slot is still bookable, then email the
     * patient a code. Nothing is reserved yet.
     */
    public function requestOtp(Request $request)
    {
        $booking = $this->validatedBooking($request);
        $user = $request->user();
        $provider = $booking['provider'];

        $sent = $this->otp->send($user, $booking['fingerprint'], [
            'provider' => $provider->display_name.($provider->job_title ? " ({$provider->job_title})" : ''),
            'when' => $booking['scheduled_at']->format('l, F j, Y \a\t g:i A'),
        ]);

        return match ($sent['status']) {
            'too_soon' => response()->json([
                'message' => "Please wait {$sent['retry_after']} seconds before asking for another code.",
                'retry_after' => $sent['retry_after'],
            ], 429),
            'failed' => response()->json([
                'message' => 'We could not send the code to your email. Please try again in a moment.',
            ], 503),
            default => response()->json([
                'message' => 'We sent a 6-digit code to '.BookingOtp::maskEmail($user->email).'.',
                'sent_to' => BookingOtp::maskEmail($user->email),
                'expires_in' => BookingOtp::TTL_SECONDS,
                'resend_in' => $sent['retry_after'],
            ]),
        };
    }

    /**
     * Step two: the right code turns the request into a booking under
     * review. The slot is re-checked under a lock, because it may have
     * filled up while the patient was reading their email.
     */
    public function store(Request $request)
    {
        $request->validate(['code' => ['required', 'string', 'digits:6']]);

        $booking = $this->validatedBooking($request);
        $user = $request->user();

        $check = $this->otp->verify($user, $booking['fingerprint'], (string) $request->input('code'));

        if ($check['result'] !== 'ok') {
            $message = match ($check['result']) {
                'expired' => 'That code has expired. Ask for a new one.',
                'other_slot' => 'That code was sent for a different time. Ask for a new one.',
                'locked' => 'Too many wrong codes. Ask for a new one.',
                default => "That code is not right. {$check['attempts_left']} ".Str::plural('try', $check['attempts_left']).' left.',
            };

            return response()->json(['message' => $message, 'errors' => ['code' => [$message]]], 422);
        }

        $appointment = DB::transaction(function () use ($booking, $user) {
            // Lock the schedule row so two patients confirming the last
            // place in a slot at the same moment cannot both get it.
            ProviderSchedule::whereKey($booking['schedule']->id)->lockForUpdate()->first();

            // Checked again under the lock: a double-tapped Confirm sends the
            // same code twice, and both requests passed the check before it.
            if ($this->hasBookingThatDay($user, $booking['provider'], $booking['scheduled_at'], lock: true)) {
                return 'duplicate';
            }

            $held = Appointment::where('provider_id', $booking['provider']->id)
                ->where('scheduled_at', $booking['scheduled_at'])
                ->whereIn('status', Appointment::SLOT_HOLDING_STATUSES)
                ->lockForUpdate()
                ->count();

            if ($held >= $booking['schedule']->capacity) {
                return null;
            }

            return Appointment::create([
                'patient_id' => $user->id,
                'provider_id' => $booking['provider']->id,
                'scheduled_at' => $booking['scheduled_at'],
                'reason' => $booking['reason'],
                'status' => 'pending',
            ]);
        });

        if ($appointment === 'duplicate') {
            return response()->json(['message' => "You already have a booking with {$booking['provider']->display_name} on that day."], 422);
        }

        if (! $appointment) {
            return response()->json(['message' => 'This slot was just taken. Please pick another time.'], 409);
        }

        app(AuditLogger::class)->log($user->id, 'appointment.requested', $appointment, [
            'provider_id' => $appointment->provider_id,
            'scheduled_at' => $appointment->scheduled_at->toIso8601String(),
            'reason' => $appointment->reason,
        ]);

        return response()->json($this->formatForPatient($appointment->load(self::PROVIDER_COLUMNS)), 201);
    }

    public function mine(Request $request)
    {
        $now = Carbon::now();

        $appointments = Appointment::with(self::PROVIDER_COLUMNS)
            ->where('patient_id', $request->user()->id)
            ->orderBy('scheduled_at')
            ->get();

        // Upcoming means still ahead of the patient: a request under review
        // whose time has not come, or a confirmed booking until its day is
        // over — someone booked for 10:00 who walks in at 10:05 still checks
        // in on their booking. Anything settled — completed, no-show,
        // cancelled, declined — is Past even when its slot is later today.
        $startOfToday = $now->copy()->startOfDay();

        [$upcoming, $past] = $appointments->partition(fn (Appointment $appointment) => match ($appointment->status) {
            'pending' => $appointment->scheduled_at->gte($now),
            'booked', 'checked_in' => $appointment->scheduled_at->gte($startOfToday),
            default => false,
        });

        return response()->json([
            'upcoming' => $upcoming->values()->map(fn (Appointment $appointment) => $this->formatForPatient($appointment, withLine: true))->all(),
            'past' => $past->sortByDesc('scheduled_at')->values()->map(fn (Appointment $appointment) => $this->formatForPatient($appointment))->all(),
        ]);
    }

    public function cancel(Request $request, Appointment $appointment)
    {
        if ($appointment->patient_id !== $request->user()->id) {
            abort(403, 'This action is unauthorized.');
        }

        // Checked in means a ticket in the nurse's queue; cancelling the
        // booking alone would leave that ticket waiting to be called.
        if ($appointment->status === 'checked_in') {
            return response()->json(['message' => "You're already checked in and in the queue. If you need to leave, please tell the nurse."], 422);
        }

        if (! in_array($appointment->status, ['pending', 'booked'], true)) {
            return response()->json(['message' => 'This appointment can no longer be cancelled.'], 422);
        }

        $validated = $request->validate([
            'cancel_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $appointment->update([
            'status' => 'cancelled',
            'cancel_reason' => $validated['cancel_reason'] ?? null,
        ]);

        app(AuditLogger::class)->log($request->user()->id, 'appointment.cancelled', $appointment, [
            'cancel_reason' => $validated['cancel_reason'] ?? null,
        ]);

        $this->notifier->lineMoved($appointment);

        return response()->json($this->formatForPatient($appointment->load(self::PROVIDER_COLUMNS)));
    }

    /**
     * Staff listing. The head nurse (admin) sees every professional's
     * bookings; the dentist and the physician only ever see their own.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d', 'required_without_all:from,status'],
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', Rule::in(Appointment::STATUSES)],
            'provider_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();

        $query = Appointment::with([self::PATIENT_COLUMNS, self::PROVIDER_COLUMNS, 'visit:id,appointment_id'])
            ->orderBy('scheduled_at')
            ->orderBy('id');

        if ($user->role === 'personnel') {
            $query->where('provider_id', $user->id);
        } elseif (! empty($validated['provider_id'])) {
            $query->where('provider_id', $validated['provider_id']);
        }

        if (! empty($validated['date'])) {
            $query->whereDate('scheduled_at', $validated['date']);
        }

        if (! empty($validated['from'])) {
            $query->whereBetween('scheduled_at', [
                Carbon::createFromFormat('Y-m-d', $validated['from'])->startOfDay(),
                Carbon::createFromFormat('Y-m-d', $validated['to'] ?? $validated['from'])->endOfDay(),
            ]);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return response()->json($query->get()->map(fn (Appointment $appointment) => $this->formatForStaff($appointment))->values());
    }

    /** The head nurse confirms a booking under review. */
    public function approve(Request $request, Appointment $appointment)
    {
        if ($appointment->status !== 'pending') {
            return response()->json(['message' => 'Only bookings under review can be confirmed.'], 422);
        }

        if ($appointment->scheduled_at->lte(Carbon::now())) {
            return response()->json(['message' => "This booking's time has already passed."], 422);
        }

        $appointment->update([
            'status' => 'booked',
            'reviewed_by_id' => $request->user()->id,
            'reviewed_at' => Carbon::now(),
        ]);

        app(AuditLogger::class)->log($request->user()->id, 'appointment.approved', $appointment, [
            'scheduled_at' => $appointment->scheduled_at->toIso8601String(),
        ]);

        $appointment->load(self::PROVIDER_COLUMNS);
        $this->notifier->changed(
            $appointment,
            'Booking confirmed',
            'With '.($appointment->provider?->display_name ?? 'the clinic').' on '.$appointment->scheduled_at->format('D, M j \a\t g:i A').'. Open Flowcast to see your place in line.',
        );

        return response()->json($this->formatForStaff($appointment->load([self::PATIENT_COLUMNS, 'visit:id,appointment_id'])));
    }

    /** The head nurse turns a booking down, saying why. */
    public function decline(Request $request, Appointment $appointment)
    {
        $validated = $request->validate([
            'review_note' => ['nullable', 'string', 'max:255'],
        ]);

        if ($appointment->status !== 'pending') {
            return response()->json(['message' => 'Only bookings under review can be declined.'], 422);
        }

        $appointment->update([
            'status' => 'declined',
            'reviewed_by_id' => $request->user()->id,
            'reviewed_at' => Carbon::now(),
            'review_note' => $validated['review_note'] ?? null,
        ]);

        app(AuditLogger::class)->log($request->user()->id, 'appointment.declined', $appointment, [
            'review_note' => $appointment->review_note,
        ]);

        $this->notifier->changed(
            $appointment,
            'Booking not confirmed',
            $appointment->review_note ?: 'Please choose another time in Flowcast.',
        );

        return response()->json($this->formatForStaff($appointment->load([self::PATIENT_COLUMNS, self::PROVIDER_COLUMNS, 'visit:id,appointment_id'])));
    }

    /**
     * A confirmed patient did not come. The dentist and the physician may
     * only mark their own patients; the head nurse may mark anyone's.
     *
     * A patient already checked in is in the queue, so they are settled
     * there: Skip, when they are not there when called, makes the booking
     * a no-show and takes them out of the line together.
     */
    public function markNoShow(Request $request, Appointment $appointment)
    {
        $user = $request->user();

        if ($user->role === 'personnel' && $appointment->provider_id !== $user->id) {
            abort(403, 'This action is unauthorized.');
        }

        if ($appointment->status === 'checked_in') {
            return response()->json(['message' => 'This patient is checked in and in the queue. If they are not there when called, skip them on the queue.'], 422);
        }

        if (! in_array($appointment->status, Appointment::OPEN_STATUSES, true)) {
            return response()->json(['message' => 'This appointment cannot be marked as a no-show.'], 422);
        }

        if ($appointment->scheduled_at->isFuture()) {
            return response()->json(['message' => 'A booking can only be marked a no-show once its time has come.'], 422);
        }

        $appointment->update(['status' => 'no_show']);

        app(AuditLogger::class)->log($user->id, 'appointment.no_show', $appointment, [
            'scheduled_at' => $appointment->scheduled_at->toIso8601String(),
        ]);

        $this->notifier->changed($appointment);
        $this->notifier->lineMoved($appointment);

        return response()->json($this->formatForStaff($appointment->load([self::PATIENT_COLUMNS, self::PROVIDER_COLUMNS, 'visit:id,appointment_id'])));
    }

    /**
     * Confirmed bookings with the same professional that day, still to be
     * seen and coming before this one — the "N people ahead of you" a
     * confirmed patient sees. Walk-ins are not counted: a booked patient is
     * called at their booked time, ahead of them.
     */
    public static function peopleAhead(Appointment $appointment): ?int
    {
        if (! in_array($appointment->status, Appointment::OPEN_STATUSES, true)) {
            return null;
        }

        // An earlier booking still unseen past the queue's grace for late
        // arrivals is most likely a no-show in the making.
        $lateCutoff = Carbon::now()->subMinutes(QueueController::LATE_GRACE_MINUTES);

        return Appointment::query()
            ->when(
                $appointment->provider_id === null,
                fn ($query) => $query->whereNull('provider_id'),
                fn ($query) => $query->where('provider_id', $appointment->provider_id),
            )
            ->whereDate('scheduled_at', $appointment->scheduled_at)
            ->whereIn('status', Appointment::OPEN_STATUSES)
            ->where(fn ($query) => $query
                ->where('scheduled_at', '<', $appointment->scheduled_at)
                ->orWhere(fn ($same) => $same->where('scheduled_at', $appointment->scheduled_at)->where('id', '<', $appointment->id)))
            // Someone already checked in is here; an unseen booking long past
            // its time is not really in front of anyone any more.
            ->where(fn ($query) => $query->where('status', 'checked_in')->orWhere('scheduled_at', '>=', $lateCutoff))
            ->count();
    }

    /**
     * Validate what is being booked and refuse anything that cannot be: an
     * incomplete health record, a past or too-distant time, an event day, a
     * time that is not a slot, a second booking with the same professional
     * that day, or a full slot.
     *
     * @return array{provider: User, schedule: ProviderSchedule, scheduled_at: Carbon, reason: string, fingerprint: string}
     */
    private function validatedBooking(Request $request): array
    {
        $validated = $request->validate([
            'provider_id' => ['required', 'integer', $this->bookableProvider()],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        $provider = User::findOrFail($validated['provider_id']);
        $scheduledAt = Carbon::parse($validated['date'].' '.$validated['time']);

        $this->refuseIf(! $user->hasCompleteHealthRecord(), 'Please complete your health record before booking.', 422, 'health_record_incomplete');
        $this->refuseIf($scheduledAt->lte(Carbon::now()), 'Cannot book a slot in the past.');
        // Whole days, like the calendar: every slot on the last bookable day counts.
        $this->refuseIf($scheduledAt->gt(Carbon::now()->addDays(self::BOOKING_HORIZON_DAYS)->endOfDay()), 'Bookings open up to '.self::BOOKING_HORIZON_DAYS.' days ahead.');
        $this->refuseIf(EventDay::whereDate('date', $scheduledAt)->exists(), 'The clinic is closed for bookings on this date.');

        $schedule = $this->scheduleFor($provider->id, $scheduledAt);
        $this->refuseIf(! $schedule || ! $schedule->hasSlotAt($scheduledAt), 'The requested time is not a valid slot.');

        $this->refuseIf(
            $this->hasBookingThatDay($user, $provider, $scheduledAt),
            "You already have a booking with {$provider->display_name} on that day.",
        );

        $held = Appointment::where('provider_id', $provider->id)
            ->where('scheduled_at', $scheduledAt)
            ->whereIn('status', Appointment::SLOT_HOLDING_STATUSES)
            ->count();
        $this->refuseIf($held >= $schedule->capacity, 'This slot is fully booked.', 409);

        return [
            'provider' => $provider,
            'schedule' => $schedule,
            'scheduled_at' => $scheduledAt,
            'reason' => trim($validated['reason']),
            'fingerprint' => sha1($provider->id.'|'.$scheduledAt->toDateTimeString()),
        ];
    }

    /** Whether the patient already holds a live booking with this professional that day. */
    private function hasBookingThatDay(User $patient, User $provider, Carbon $day, bool $lock = false): bool
    {
        return Appointment::where('patient_id', $patient->id)
            ->where('provider_id', $provider->id)
            ->whereDate('scheduled_at', $day)
            ->whereIn('status', ['pending', 'booked', 'checked_in'])
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->exists();
    }

    private function refuseIf(bool $condition, string $message, int $status = 422, ?string $code = null): void
    {
        if ($condition) {
            throw new HttpResponseException(response()->json(array_filter(['message' => $message, 'code' => $code]), $status));
        }
    }

    private function bookableProvider(): Exists
    {
        return Rule::exists('users', 'id')->whereNotNull('profession')->whereIn('role', User::STAFF_ROLES);
    }

    private function scheduleFor(int $providerId, Carbon $date): ?ProviderSchedule
    {
        return ProviderSchedule::where('user_id', $providerId)
            ->where('day_of_week', $date->dayOfWeek)
            ->where('is_active', true)
            ->first();
    }

    /**
     * @return array<int, array{time: string, datetime: string, capacity: int, booked: int, available: bool}>
     */
    private function buildSlots(ProviderSchedule $schedule, Carbon $date): array
    {
        $held = Appointment::where('provider_id', $schedule->user_id)
            ->whereDate('scheduled_at', $date)
            ->whereIn('status', Appointment::SLOT_HOLDING_STATUSES)
            ->get(['scheduled_at'])
            ->countBy(fn (Appointment $appointment) => $appointment->scheduled_at->format('H:i:s'));

        $now = Carbon::now();

        return collect($schedule->slotStarts($date))
            ->filter(fn (Carbon $slot) => $slot->greaterThan($now))
            ->map(function (Carbon $slot) use ($schedule, $held) {
                $booked = $held->get($slot->format('H:i:s'), 0);

                return [
                    'time' => $slot->format('H:i'),
                    'datetime' => $slot->toDateTimeString(),
                    'capacity' => $schedule->capacity,
                    'booked' => $booked,
                    'available' => $booked < $schedule->capacity,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatForPatient(Appointment $appointment, bool $withLine = false): array
    {
        return [
            'id' => $appointment->id,
            'scheduled_at' => $appointment->scheduled_at->toIso8601String(),
            'reason' => $appointment->reason,
            'status' => $appointment->status,
            'cancel_reason' => $appointment->cancel_reason,
            'review_note' => $appointment->review_note,
            'reviewed_at' => $appointment->reviewed_at?->toIso8601String(),
            'provider' => $this->formatProvider($appointment->provider),
            'goes_through_queue' => $appointment->goesThroughQueue(),
            'people_ahead' => $withLine ? self::peopleAhead($appointment) : null,
            'created_at' => $appointment->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatForStaff(Appointment $appointment): array
    {
        $patient = $appointment->patient;

        return [
            'id' => $appointment->id,
            'scheduled_at' => $appointment->scheduled_at->toIso8601String(),
            'reason' => $appointment->reason,
            'status' => $appointment->status,
            'cancel_reason' => $appointment->cancel_reason,
            'review_note' => $appointment->review_note,
            'reviewed_at' => $appointment->reviewed_at?->toIso8601String(),
            'created_at' => $appointment->created_at?->toIso8601String(),
            'goes_through_queue' => $appointment->goesThroughQueue(),
            'visit_id' => $appointment->visit?->id,
            'provider' => $this->formatProvider($appointment->provider),
            'patient' => [
                'id' => $patient->id,
                'name' => $patient->name,
                'email' => $patient->email,
                'contact_no' => $patient->contact_no,
                'school_id' => $patient->school_id,
                'id_verified' => $patient->hasVerifiedSchoolId(),
                'department' => $patient->department,
                'course' => $patient->course,
                'sex' => $patient->sex,
                'birthdate' => $patient->birthdate?->toDateString(),
                'patient_type' => $patient->patient_type,
            ],
        ];
    }

    /**
     * @return array{id: int, display_name: string, job_title: string|null, profession: string|null}|null
     */
    private function formatProvider(?User $provider): ?array
    {
        if (! $provider) {
            return null;
        }

        return [
            'id' => $provider->id,
            'display_name' => $provider->display_name,
            'job_title' => $provider->job_title,
            'profession' => $provider->profession,
        ];
    }
}
