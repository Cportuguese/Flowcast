<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\ClinicSchedule;
use App\Models\EventDay;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AppointmentController extends Controller
{
    public function availableSlots(Request $request)
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $date = Carbon::createFromFormat('Y-m-d', $validated['date'])->startOfDay();

        $eventDay = EventDay::whereDate('date', $date)->first();

        if ($eventDay) {
            return response()->json([
                'date' => $date->toDateString(),
                'is_event_day' => true,
                'event' => $eventDay,
                'slots' => [],
            ]);
        }

        $schedule = ClinicSchedule::where('day_of_week', $date->dayOfWeek)
            ->where('is_active', true)
            ->first();

        if (! $schedule) {
            return response()->json([
                'date' => $date->toDateString(),
                'is_event_day' => false,
                'slots' => [],
            ]);
        }

        return response()->json([
            'date' => $date->toDateString(),
            'is_event_day' => false,
            'slots' => $this->buildSlots($date, $schedule),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $date = Carbon::createFromFormat('Y-m-d', $validated['date'])->startOfDay();
        $scheduledAt = Carbon::parse($validated['date'].' '.$validated['time']);

        if ($scheduledAt->lte(Carbon::now())) {
            return response()->json(['message' => 'Cannot book a slot in the past.'], 422);
        }

        if (EventDay::whereDate('date', $date)->exists()) {
            return response()->json(['message' => 'The clinic is closed on this date.'], 422);
        }

        $schedule = ClinicSchedule::where('day_of_week', $date->dayOfWeek)
            ->where('is_active', true)
            ->first();

        if (! $schedule || ! $this->isValidSlot($date, $scheduledAt, $schedule)) {
            return response()->json(['message' => 'The requested time is not a valid slot.'], 422);
        }

        $appointment = DB::transaction(function () use ($schedule, $scheduledAt, $request, $validated) {
            $lockedSchedule = ClinicSchedule::where('id', $schedule->id)->lockForUpdate()->first();

            $booked = Appointment::where('scheduled_at', $scheduledAt)
                ->whereNotIn('status', ['cancelled'])
                ->lockForUpdate()
                ->count();

            if ($booked >= $lockedSchedule->capacity) {
                return null;
            }

            return Appointment::create([
                'patient_id' => $request->user()->id,
                'scheduled_at' => $scheduledAt,
                'reason' => $validated['reason'],
                'status' => 'booked',
            ]);
        });

        if (! $appointment) {
            return response()->json(['message' => 'This slot is fully booked.'], 409);
        }

        return response()->json($appointment, 201);
    }

    public function mine(Request $request)
    {
        $now = Carbon::now();

        $appointments = Appointment::where('patient_id', $request->user()->id)
            ->orderBy('scheduled_at')
            ->get();

        return response()->json([
            'upcoming' => $appointments->where('scheduled_at', '>=', $now)->values(),
            'past' => $appointments->where('scheduled_at', '<', $now)->sortByDesc('scheduled_at')->values(),
        ]);
    }

    public function cancel(Request $request, Appointment $appointment)
    {
        if ($appointment->patient_id !== $request->user()->id) {
            abort(403, 'This action is unauthorized.');
        }

        if (in_array($appointment->status, ['cancelled', 'completed'], true)) {
            return response()->json(['message' => 'This appointment can no longer be cancelled.'], 422);
        }

        $validated = $request->validate([
            'cancel_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $appointment->update([
            'status' => 'cancelled',
            'cancel_reason' => $validated['cancel_reason'] ?? null,
        ]);

        return response()->json($appointment);
    }

    public function forDate(Request $request)
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $appointments = Appointment::whereDate('scheduled_at', $validated['date'])
            ->with('patient:id,name,email,contact_no')
            ->orderBy('scheduled_at')
            ->get();

        return response()->json($appointments);
    }

    private function buildSlots(Carbon $date, ClinicSchedule $schedule): array
    {
        $cursor = Carbon::parse($date->toDateString().' '.$schedule->start_time);
        $end = Carbon::parse($date->toDateString().' '.$schedule->end_time);

        $slotStarts = [];

        while ($cursor->copy()->addMinutes($schedule->slot_minutes)->lte($end)) {
            $slotStarts[] = $cursor->copy();
            $cursor->addMinutes($schedule->slot_minutes);
        }

        $bookedCounts = Appointment::whereDate('scheduled_at', $date)
            ->whereNotIn('status', ['cancelled'])
            ->get()
            ->countBy(fn (Appointment $appointment) => $appointment->scheduled_at->format('H:i:s'));

        $now = Carbon::now();

        return collect($slotStarts)
            ->filter(fn (Carbon $slot) => $slot->greaterThan($now))
            ->map(function (Carbon $slot) use ($schedule, $bookedCounts) {
                $booked = $bookedCounts->get($slot->format('H:i:s'), 0);

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

    private function isValidSlot(Carbon $date, Carbon $scheduledAt, ClinicSchedule $schedule): bool
    {
        $scheduleStart = Carbon::parse($date->toDateString().' '.$schedule->start_time);
        $scheduleEnd = Carbon::parse($date->toDateString().' '.$schedule->end_time);

        if ($scheduledAt->lt($scheduleStart) || $scheduledAt->copy()->addMinutes($schedule->slot_minutes)->gt($scheduleEnd)) {
            return false;
        }

        return $scheduleStart->diffInMinutes($scheduledAt, false) % $schedule->slot_minutes === 0;
    }
}
