<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Tells one patient's phone that one of their bookings changed (confirmed,
 * declined, finished, or the line ahead of it moved) so it can reload.
 * Carries ids and a status only — the phone fetches the details itself
 * over its own authenticated request.
 */
class AppointmentUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $patientId,
        public int $appointmentId,
        public string $status,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("App.Models.User.{$this->patientId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'appointment.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'appointment_id' => $this->appointmentId,
            'status' => $this->status,
        ];
    }
}
