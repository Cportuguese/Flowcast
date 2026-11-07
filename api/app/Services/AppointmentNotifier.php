<?php

namespace App\Services;

use App\Events\AppointmentUpdated;
use App\Models\Appointment;
use App\Models\User;
use App\Support\Realtime;

/**
 * Keeps patients' phones in step with their bookings. Best-effort, like the
 * queue broadcasts: the change is already saved, so a down Reverb server or
 * an unreachable Expo is reported and swallowed, never surfaced as an error
 * on the nurse's or dentist's screen.
 */
class AppointmentNotifier
{
    public function __construct(private readonly ExpoPushNotifier $push) {}

    /**
     * The booking itself changed: refresh that patient's app, and push a
     * notification when there is something they need to read.
     */
    public function changed(Appointment $appointment, ?string $title = null, ?string $body = null): void
    {
        $this->broadcast($appointment->patient_id, $appointment);

        if ($title !== null && $body !== null) {
            $patient = User::find($appointment->patient_id);

            if ($patient) {
                $this->push->send($patient, $title, $body, ['appointment_id' => $appointment->id]);
            }
        }
    }

    /**
     * Someone ahead in the same professional's line that day finished or
     * missed their slot, so everyone after them is one place closer.
     */
    public function lineMoved(Appointment $appointment): void
    {
        Appointment::query()
            ->where('provider_id', $appointment->provider_id)
            ->whereDate('scheduled_at', $appointment->scheduled_at)
            ->whereIn('status', Appointment::OPEN_STATUSES)
            ->whereKeyNot($appointment->id)
            ->get(['id', 'patient_id', 'status'])
            ->each(fn (Appointment $other) => $this->broadcast($other->patient_id, $other));
    }

    private function broadcast(int $patientId, Appointment $appointment): void
    {
        Realtime::broadcast(new AppointmentUpdated($patientId, $appointment->id, $appointment->status));
    }
}
