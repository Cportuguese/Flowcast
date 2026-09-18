<?php

namespace App\Http\Controllers\Api;

use App\Events\QueueEntryUpdated;
use App\Events\QueueUpdated;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\QueueEntry;
use App\Models\User;
use App\Services\ExpoPushNotifier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class QueueController extends Controller
{
    public function checkIn(Request $request)
    {
        $user = $request->user();
        $isStaff = in_array($user->role, ['admin', 'personnel'], true);

        $validated = $request->validate([
            'patient_id' => [$isStaff ? 'required' : 'prohibited', 'integer', 'exists:users,id'],
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
            $appointment = Appointment::where('id', $validated['appointment_id'])
                ->where('patient_id', $patientId)
                ->first();

            if (! $appointment) {
                return response()->json(['message' => 'Appointment not found for this patient.'], 422);
            }

            if ($appointment->status !== 'booked') {
                return response()->json(['message' => 'This appointment cannot be checked in.'], 422);
            }
        }

        $entry = $this->assignQueueEntry($patientId, $appointment, $today);

        $this->broadcastQueue($today);

        return response()->json($this->formatEntry($entry), 201);
    }

    /**
     * Register a walk-in patient who has no account yet and check them
     * straight into today's queue. Personnel/admin only.
     */
    public function walkIn(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_no' => ['nullable', 'string', 'max:32'],
            'patient_type' => ['nullable', Rule::in(['student', 'faculty', 'staff'])],
        ]);

        $patient = User::create([
            'name' => $validated['name'],
            'email' => 'walkin_'.Str::uuid()->toString().'@flowcast.local',
            'password' => Hash::make(Str::random(32)),
            'role' => 'patient',
            'contact_no' => $validated['contact_no'] ?? null,
            'patient_type' => $validated['patient_type'] ?? null,
        ]);

        $today = Carbon::today();

        $entry = $this->assignQueueEntry($patient->id, null, $today);

        $this->broadcastQueue($today);

        return response()->json($this->formatEntry($entry), 201);
    }

    private function assignQueueEntry(int $patientId, ?Appointment $appointment, Carbon $today): QueueEntry
    {
        $entry = DB::transaction(function () use ($patientId, $appointment, $today) {
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

        return $entry;
    }

    public function callNext(Request $request)
    {
        $today = Carbon::today();

        $entry = QueueEntry::where('queue_date', $today)
            ->where('status', 'waiting')
            ->orderBy('queue_number')
            ->first();

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

        $this->broadcastQueue($today);

        return response()->json($this->formatEntry($entry->fresh()));
    }

    public function complete(QueueEntry $queueEntry)
    {
        if (in_array($queueEntry->status, ['done', 'skipped'], true)) {
            return response()->json(['message' => 'This queue entry has already been resolved.'], 422);
        }

        $queueEntry->update([
            'status' => 'done',
            'completed_at' => now(),
        ]);

        $this->broadcastQueue($queueEntry->queue_date);

        return response()->json($this->formatEntry($queueEntry));
    }

    public function skip(QueueEntry $queueEntry)
    {
        if (in_array($queueEntry->status, ['done', 'skipped'], true)) {
            return response()->json(['message' => 'This queue entry has already been resolved.'], 422);
        }

        $queueEntry->update([
            'status' => 'skipped',
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

        $peopleAhead = QueueEntry::where('queue_date', $today)
            ->where('status', 'waiting')
            ->where('queue_number', '<', $entry->queue_number)
            ->count();

        return response()->json([
            'date' => $today->toDateString(),
            'entry' => $this->formatEntry($entry),
            'position' => $peopleAhead + 1,
            'people_ahead' => $peopleAhead,
        ]);
    }

    /**
     * @return Collection<int, QueueEntry>
     */
    private function entriesFor(Carbon $date): Collection
    {
        return QueueEntry::where('queue_date', $date)
            ->with('patient:id,name,contact_no')
            ->orderBy('queue_number')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatEntry(QueueEntry $entry): array
    {
        $entry->loadMissing('patient:id,name,contact_no');

        return [
            'id' => $entry->id,
            'queue_number' => $entry->queue_number,
            'status' => $entry->status,
            'source' => $entry->source,
            'appointment_id' => $entry->appointment_id,
            'patient' => [
                'id' => $entry->patient->id,
                'name' => $entry->patient->name,
                'contact_no' => $entry->patient->contact_no,
            ],
            'joined_at' => optional($entry->joined_at)->toIso8601String(),
            'called_at' => optional($entry->called_at)->toIso8601String(),
            'completed_at' => optional($entry->completed_at)->toIso8601String(),
        ];
    }

    /**
     * Broadcast the full board to staff, and each patient their own sanitized
     * entry on their private channel. Also fires the one-shot "almost your
     * turn" push once a waiting patient has two or fewer people ahead.
     */
    private function broadcastQueue(Carbon $date): void
    {
        $entries = $this->entriesFor($date);

        broadcast(new QueueUpdated(
            $date->toDateString(),
            $entries->map(fn (QueueEntry $entry) => $this->formatEntry($entry))->values()->all(),
        ));

        $peopleAhead = 0;

        foreach ($entries as $entry) {
            $isWaiting = $entry->status === 'waiting';

            broadcast(new QueueEntryUpdated($entry->patient_id, [
                'date' => $date->toDateString(),
                'entry' => $this->formatEntry($entry),
                'position' => $isWaiting ? $peopleAhead + 1 : null,
                'people_ahead' => $isWaiting ? $peopleAhead : null,
            ]));

            if ($isWaiting) {
                if ($peopleAhead <= 2 && ! $entry->near_call_notified_at) {
                    app(ExpoPushNotifier::class)->send(
                        $entry->patient,
                        'Almost your turn',
                        'You are almost up — please head to the clinic now.',
                        ['queue_entry_id' => $entry->id],
                    );
                    $entry->update(['near_call_notified_at' => now()]);
                }

                $peopleAhead++;
            }
        }
    }
}
