<?php

namespace Tests\Feature\Security;

use App\Models\Appointment;
use App\Models\EventDay;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\QueueEntry;
use App\Models\User;
use App\Models\VisitRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every protected route × every role. Fails the moment a new endpoint is
 * added without `auth:sanctum`, or with the wrong `role:` list. When you add
 * a route, add a row here.
 */
class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The allowed-role calls really run (e.g. issuing a certificate
        // renders a PDF); keep that off the real disk.
        Storage::fake('local');
    }

    private const ALL = ['admin', 'personnel', 'patient'];

    private const STAFF = ['admin', 'personnel'];

    /**
     * @return array<string, array{string, string, array<int, string>}>
     */
    public static function routes(): array
    {
        return [
            // Phase 1 — auth
            'logout' => ['POST', '/api/logout', self::ALL],
            'user' => ['GET', '/api/user', self::ALL],
            'push-token' => ['PATCH', '/api/user/push-token', self::ALL],
            'password' => ['PATCH', '/api/user/password', self::ALL],
            'admin ping' => ['GET', '/api/admin/ping', ['admin']],
            'personnel ping' => ['GET', '/api/personnel/ping', self::STAFF],

            // Phase 2 — schedules & appointments
            'schedules index' => ['GET', '/api/admin/schedules', ['admin']],
            'schedules store' => ['POST', '/api/admin/schedules', ['admin']],
            'event-days index' => ['GET', '/api/admin/event-days', ['admin']],
            'event-days store' => ['POST', '/api/admin/event-days', ['admin']],
            'event-days destroy' => ['DELETE', '/api/admin/event-days/{eventDay}', ['admin']],
            'schedule active' => ['GET', '/api/schedule', self::ALL],
            'available slots' => ['GET', '/api/appointments/available-slots', self::ALL],
            'appointments store' => ['POST', '/api/appointments', ['patient']],
            'appointments mine' => ['GET', '/api/appointments/mine', ['patient']],
            'appointments cancel' => ['POST', '/api/appointments/{appointment}/cancel', ['patient']],
            'appointments for date' => ['GET', '/api/appointments', self::STAFF],
            'appointments no-show' => ['PATCH', '/api/appointments/{appointment}/no-show', self::STAFF],

            // Phase 3 — queue
            'queue check-in' => ['POST', '/api/queue/check-in', self::ALL],
            'queue mine' => ['GET', '/api/queue/mine', ['patient']],
            'queue today' => ['GET', '/api/queue/today', self::STAFF],
            'queue call-next' => ['POST', '/api/admin/queue/call-next', self::STAFF],
            'queue walk-in' => ['POST', '/api/admin/queue/walk-in', self::STAFF],
            'queue complete' => ['PATCH', '/api/admin/queue/{queueEntry}/complete', self::STAFF],
            'queue skip' => ['PATCH', '/api/admin/queue/{queueEntry}/skip', self::STAFF],
            'queue history' => ['GET', '/api/queue/2026-01-01/history', ['admin']],

            // Phase 4 — visits
            'visits store' => ['POST', '/api/visits', self::STAFF],
            'visits search' => ['GET', '/api/admin/visits/search', self::STAFF],
            'visits mine' => ['GET', '/api/visits/mine', ['patient']],
            'visits show' => ['GET', '/api/visits/{queueEntry}', self::ALL],
            'certificate issue' => ['POST', '/api/visits/{visit}/certificate', ['personnel']],
            'certificate show' => ['GET', '/api/visits/{visit}/certificate', self::ALL],
            'certificate download' => ['GET', '/api/visits/{visit}/certificate/download', self::ALL],

            // Phase 5 — analytics
            'analytics dashboard' => ['GET', '/api/analytics/dashboard', self::STAFF],
            'analytics volume-forecast' => ['GET', '/api/analytics/volume-forecast', self::STAFF],
            'analytics wait-time-forecast' => ['GET', '/api/analytics/wait-time-forecast', self::STAFF],
            'analytics peak-hours' => ['GET', '/api/analytics/peak-hours', self::STAFF],
            'analytics staffing-what-if' => ['GET', '/api/analytics/staffing-what-if', self::STAFF],
            'analytics model-performance' => ['GET', '/api/analytics/model-performance', ['admin']],
            'audit-logs index' => ['GET', '/api/admin/audit-logs', ['admin']],
            'reports queue-summary' => ['GET', '/api/admin/reports/queue-summary', ['admin']],
            'reports visit-summary' => ['GET', '/api/admin/reports/visit-summary', ['admin']],
            'reports no-show-summary' => ['GET', '/api/admin/reports/no-show-summary', ['admin']],
            'reports peak-hours-by-weekday' => ['GET', '/api/admin/reports/peak-hours-by-weekday', ['admin']],

            // Phase 6 — inventory
            'medicines index' => ['GET', '/api/medicines', self::STAFF],
            'medicines store' => ['POST', '/api/admin/medicines', ['admin']],
            'medicines update' => ['PATCH', '/api/admin/medicines/{medicine}', ['admin']],
            'inventory stock-in' => ['POST', '/api/inventory/stock-in', self::STAFF],
            'inventory stock-out' => ['POST', '/api/inventory/stock-out', self::STAFF],
            'inventory low-stock' => ['GET', '/api/inventory/low-stock', self::STAFF],
            'inventory expiring-soon' => ['GET', '/api/inventory/expiring-soon', self::STAFF],
            'inventory expired' => ['GET', '/api/admin/inventory/expired', ['admin']],
            'inventory dispose' => ['POST', '/api/admin/inventory/batches/{batch}/dispose', ['admin']],
            'inventory history' => ['GET', '/api/admin/inventory/history', ['admin']],
        ];
    }

    #[DataProvider('routes')]
    public function test_route_rejects_unauthenticated_requests(string $method, string $uri): void
    {
        $this->json($method, $this->resolve($uri, null))->assertUnauthorized();
    }

    #[DataProvider('routes')]
    public function test_route_enforces_roles(string $method, string $uri, array $allowed): void
    {
        foreach (self::ALL as $role) {
            $user = User::factory()->{$role}()->create();
            Sanctum::actingAs($user);

            $status = $this->json($method, $this->resolve($uri, $user))->status();

            if (in_array($role, $allowed, true)) {
                $this->assertNotContains($status, [401, 403], "{$role} should be allowed on {$method} {$uri}, got {$status}");
            } else {
                $this->assertSame(403, $status, "{$role} should get 403 on {$method} {$uri}, got {$status}");
            }
        }
    }

    public function test_public_board_needs_no_token_and_exposes_no_patient_data(): void
    {
        QueueEntry::factory()->create();

        $this->getJson('/api/queue-board/today')
            ->assertOk()
            ->assertJsonStructure(['date', 'entries' => [['queue_number', 'status']]])
            ->assertJsonMissingPath('entries.0.patient');
    }

    /**
     * Swap route placeholders for real rows. Owned rows belong to the acting
     * user where ownership matters, so an allowed role isn't tripped by a
     * 403 from the controller's own ownership check.
     */
    private function resolve(string $uri, ?User $actor): string
    {
        if (! str_contains($uri, '{')) {
            return $uri;
        }

        $patient = $actor && $actor->role === 'patient' ? $actor : User::factory()->patient()->create();

        if (str_contains($uri, '{eventDay}')) {
            $id = EventDay::create(['date' => today()->addDays(rand(1, 3000)), 'label' => 'x'])->id;

            return str_replace('{eventDay}', (string) $id, $uri);
        }

        if (str_contains($uri, '{medicine}')) {
            return str_replace('{medicine}', (string) Medicine::factory()->create()->id, $uri);
        }

        if (str_contains($uri, '{batch}')) {
            return str_replace('{batch}', (string) MedicineBatch::factory()->expired()->create()->id, $uri);
        }

        if (str_contains($uri, '{appointment}')) {
            $id = Appointment::factory()->for($patient, 'patient')->create()->id;

            return str_replace('{appointment}', (string) $id, $uri);
        }

        $entry = QueueEntry::factory()->for($patient, 'patient')->create();
        $visit = VisitRecord::factory()->for($entry, 'queueEntry')->create(['patient_id' => $patient->id]);

        if (str_contains($uri, '{visit}')) {
            return str_replace('{visit}', (string) $visit->id, $uri);
        }

        return str_replace('{queueEntry}', (string) $entry->id, $uri);
    }
}
