<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Full-board update for the staff queue dashboard. Carries every entry
 * (including patient names/contact numbers) so it must stay on the
 * role-gated private channel — never make this a public channel.
 */
class QueueUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    public function __construct(
        public string $date,
        public array $entries,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("queue.{$this->date}.staff"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'queue.updated';
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
