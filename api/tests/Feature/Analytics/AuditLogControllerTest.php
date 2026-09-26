<?php

namespace Tests\Feature\Analytics;

use App\Models\AuditLog;
use App\Models\QueueEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditLogControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_filters_by_date_range(): void
    {
        $this->logAt(Carbon::parse('2026-01-01'));
        $inRange = $this->logAt(Carbon::parse('2026-02-01'));
        $this->logAt(Carbon::parse('2026-03-01'));

        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->getJson('/api/admin/audit-logs?date_from=2026-01-15&date_to=2026-02-15')->assertOk();

        $this->assertSame([$inRange->id], collect($response->json())->pluck('id')->all());
    }

    public function test_filters_by_user(): void
    {
        $user = User::factory()->personnel()->create();
        $mine = AuditLog::factory()->create(['user_id' => $user->id]);
        AuditLog::factory()->create();

        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->getJson("/api/admin/audit-logs?user_id={$user->id}")->assertOk();

        $this->assertSame([$mine->id], collect($response->json())->pluck('id')->all());
    }

    public function test_filters_by_action_substring(): void
    {
        $match = AuditLog::factory()->create(['action' => 'queue.completed']);
        AuditLog::factory()->create(['action' => 'appointment.booked']);

        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->getJson('/api/admin/audit-logs?action=queue')->assertOk();

        $this->assertSame([$match->id], collect($response->json())->pluck('id')->all());
    }

    public function test_action_filter_treats_wildcards_as_literal_text(): void
    {
        AuditLog::factory()->create(['action' => 'queue.completed']);
        AuditLog::factory()->create(['action' => 'appointment.booked']);
        $literal = AuditLog::factory()->create(['action' => 'odd%action']);

        Sanctum::actingAs(User::factory()->admin()->create());

        // A bare "%" would otherwise match every row; "_" would match any char.
        $percent = $this->getJson('/api/admin/audit-logs?action=%25')->assertOk();
        $this->assertSame([$literal->id], collect($percent->json())->pluck('id')->all());

        $this->assertCount(0, $this->getJson('/api/admin/audit-logs?action=_')->assertOk()->json());

        $response = $this->getJson('/api/admin/audit-logs?action=d%25a')->assertOk();
        $this->assertSame([$literal->id], collect($response->json())->pluck('id')->all());
    }

    public function test_filters_by_patient_id_via_the_related_model(): void
    {
        $patient = User::factory()->patient()->create();
        $entry = QueueEntry::factory()->for($patient, 'patient')->create();
        $mine = AuditLog::factory()->create(['model_type' => 'QueueEntry', 'model_id' => $entry->id]);

        $otherEntry = QueueEntry::factory()->create();
        AuditLog::factory()->create(['model_type' => 'QueueEntry', 'model_id' => $otherEntry->id]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->getJson("/api/admin/audit-logs?patient={$patient->id}")->assertOk();

        $this->assertSame([$mine->id], collect($response->json())->pluck('id')->all());
    }

    public function test_filters_by_patient_name(): void
    {
        $patient = User::factory()->patient()->create(['name' => 'Juan Dela Cruz']);
        $entry = QueueEntry::factory()->for($patient, 'patient')->create();
        $mine = AuditLog::factory()->create(['model_type' => 'QueueEntry', 'model_id' => $entry->id]);

        AuditLog::factory()->create();

        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->getJson('/api/admin/audit-logs?patient=Dela+Cruz')->assertOk();

        $this->assertSame([$mine->id], collect($response->json())->pluck('id')->all());
    }

    private function logAt(Carbon $date, array $attributes = []): AuditLog
    {
        $log = AuditLog::factory()->create($attributes);
        $log->forceFill(['created_at' => $date])->save();

        return $log->fresh();
    }
}
