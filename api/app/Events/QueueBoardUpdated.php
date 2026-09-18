<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Public lobby/TV board update. Deliberately carries only queue numbers
 * and statuses — no patient names or contact info — since this channel
 * has no authorization and is meant to be shown on a waiting-room monitor.
 */
class QueueBoardUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, array{queue_number: int, status: string}>  $entries
     */
    public function __construct(
        public string $date,
        public array $entries,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel("queue-board.{$this->date}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'queue.board.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'date' => $this->date,
            'entries' => $this->entries,
        ];
    }
}
