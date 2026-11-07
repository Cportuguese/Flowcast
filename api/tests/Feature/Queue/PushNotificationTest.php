<?php

namespace Tests\Feature\Queue;

use App\Models\QueueEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'ExponentPushToken[test-device]';

    public function test_calling_a_patient_pushes_to_their_device(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => ['status' => 'ok']])]);
        $this->waiting(1, self::TOKEN);
        Sanctum::actingAs(User::factory()->nurse()->create());

        $this->postJson('/api/admin/queue/call-next')->assertOk();

        Http::assertSent(fn (Request $request) => $request['to'] === self::TOKEN
            && $request['title'] === "You're up!");
    }

    /**
     * Queue lists load patients with a column subset. When push_token was not
     * in it, this push was skipped for every patient while
     * near_call_notified_at was still stamped, so nothing looked wrong.
     */
    public function test_the_patient_next_in_line_gets_an_almost_your_turn_push(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => ['status' => 'ok']])]);
        $this->waiting(1);
        $this->waiting(2, self::TOKEN);
        Sanctum::actingAs(User::factory()->nurse()->create());

        $this->postJson('/api/admin/queue/call-next')->assertOk();

        Http::assertSent(fn (Request $request) => $request['to'] === self::TOKEN
            && $request['title'] === 'Almost your turn');
    }

    /**
     * push_token is loaded with queue patients so the push above can go out.
     * It must never leave the server in a queue payload.
     */
    public function test_push_tokens_never_appear_in_queue_payloads(): void
    {
        $this->waiting(1, self::TOKEN);
        Sanctum::actingAs(User::factory()->nurse()->create());

        $this->assertStringNotContainsString(self::TOKEN, $this->getJson('/api/queue/today')->assertOk()->getContent());
        $this->assertStringNotContainsString(self::TOKEN, $this->getJson('/api/queue-board/today')->assertOk()->getContent());
    }

    /**
     * The queue row is already written by the time the push goes out. An
     * unreachable Expo (no outbound internet on the clinic server, DNS, an
     * outage) must not turn that into a 500 with no audit entry and no
     * board update.
     */
    public function test_call_next_succeeds_when_expo_is_unreachable(): void
    {
        Http::fake(['exp.host/*' => Http::failedConnection()]);
        $entry = $this->waiting(1, self::TOKEN);
        Sanctum::actingAs(User::factory()->nurse()->create());

        $this->postJson('/api/admin/queue/call-next')
            ->assertOk()
            ->assertJsonPath('status', 'in_consultation');

        $this->assertDatabaseHas('audit_logs', ['action' => 'queue.called', 'model_id' => $entry->id]);
    }

    /**
     * The "almost your turn" push is one-shot. A failed attempt must still
     * mark it sent, or every later check-in, call, completion and skip would
     * retry it on each request.
     */
    public function test_a_failed_near_call_push_is_attempted_once_and_does_not_block_later_actions(): void
    {
        $attempts = [];
        Http::fake(['exp.host/*' => function (Request $request) use (&$attempts) {
            $attempts[] = $request['title'];

            return Http::failedConnection()($request);
        }]);

        $first = $this->waiting(1);
        $this->waiting(2, self::TOKEN);
        Sanctum::actingAs(User::factory()->nurse()->create());

        $this->postJson('/api/admin/queue/call-next')->assertOk();
        $this->patchJson("/api/admin/queue/{$first->id}/complete")->assertOk();
        $this->postJson('/api/admin/queue/call-next')->assertOk();

        $this->assertSame(['Almost your turn', "You're up!"], $attempts);
    }

    private function waiting(int $number, ?string $pushToken = null): QueueEntry
    {
        $patient = User::factory()->patient()->create(['push_token' => $pushToken]);

        return QueueEntry::factory()->for($patient, 'patient')->create(['queue_number' => $number]);
    }
}
