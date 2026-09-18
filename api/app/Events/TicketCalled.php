<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired the moment a specific ticket is called, separate from the general
 * board refresh, so the TV board knows exactly when to play its chime and
 * the patient's app knows exactly when to alert — not on every reshuffle.
 */
class TicketCalled implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $date,
        public int $queueNumber,
        public int $patientId,
    ) {}

    /**
     * @return array<int, Channel|PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel("queue-board.{$this->date}"),
            new PrivateChannel("App.Models.User.{$this->patientId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ticket.called';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'date' => $this->date,
            'queue_number' => $this->queueNumber,
        ];
    }
}
