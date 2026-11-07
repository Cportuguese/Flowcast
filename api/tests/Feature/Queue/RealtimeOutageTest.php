<?php

namespace Tests\Feature\Queue;

use App\Events\QueueBoardUpdated;
use App\Models\QueueEntry;
use App\Models\User;
use App\Support\Realtime;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * With the realtime server down, each live update used to wait for a
 * connection that never came; a queue action makes a dozen of them, which
 * ran the nurse's click past PHP's time limit. Now one failure pauses live
 * updates briefly, and the click goes through.
 */
class RealtimeOutageTest extends TestCase
{
    use RefreshDatabase;

    private int $attempts = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(BroadcastFactory::class, function ($mock) {
            $mock->shouldReceive('event')->andReturnUsing(function () {
                $this->attempts++;

                throw new RuntimeException('Reverb is down');
            });
        });
    }

    public function test_a_down_server_is_tried_once_then_skipped_for_a_while(): void
    {
        foreach (range(1, 5) as $ignored) {
            Realtime::broadcast(new QueueBoardUpdated('2026-10-13', []));
        }
        $this->assertSame(1, $this->attempts);

        $this->travel(31)->seconds();
        Realtime::broadcast(new QueueBoardUpdated('2026-10-13', []));
        $this->assertSame(2, $this->attempts);
    }

    public function test_queue_actions_still_go_through(): void
    {
        $entries = QueueEntry::factory()->count(6)->create(['queue_date' => today(), 'status' => 'waiting']);
        Sanctum::actingAs(User::factory()->nurse()->create());

        $this->postJson('/api/admin/queue/call-next')->assertOk();
        $this->patchJson("/api/admin/queue/{$entries->first()->id}/complete")->assertOk()->assertJsonPath('status', 'done');

        // One attempt, not one per announcement.
        $this->assertSame(1, $this->attempts);
    }
}
