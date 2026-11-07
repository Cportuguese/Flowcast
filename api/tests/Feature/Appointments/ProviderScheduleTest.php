<?php

namespace Tests\Feature\Appointments;

use App\Models\ProviderSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The head nurse sets when each professional can be booked.
 */
class ProviderScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_head_nurse_sets_a_professionals_hours_for_a_weekday(): void
    {
        $physician = User::factory()->physician()->create();
        Sanctum::actingAs(User::factory()->nurse()->create());

        $hours = ['day_of_week' => 5, 'start_time' => '13:00', 'end_time' => '15:00', 'slot_minutes' => 20, 'capacity' => 1];

        $this->postJson("/api/admin/providers/{$physician->id}/schedules", $hours)
            ->assertOk()
            ->assertJsonPath('start_time', '13:00');

        // Saving the same weekday again replaces it rather than adding a row.
        $this->postJson("/api/admin/providers/{$physician->id}/schedules", [...$hours, 'end_time' => '16:00'])->assertOk();

        $this->assertSame(1, ProviderSchedule::where('user_id', $physician->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'provider_schedule.updated']);
    }

    public function test_only_professionals_have_a_booking_schedule(): void
    {
        $patient = User::factory()->patient()->create();
        Sanctum::actingAs(User::factory()->nurse()->create());

        $this->postJson("/api/admin/providers/{$patient->id}/schedules", [
            'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '17:00', 'slot_minutes' => 20, 'capacity' => 1,
        ])->assertUnprocessable();
    }

    public function test_a_professional_with_no_active_hours_is_not_offered(): void
    {
        $dentist = User::factory()->dentist()->create();
        ProviderSchedule::factory()->for($dentist)->create(['is_active' => false]);
        Sanctum::actingAs(User::factory()->patient()->create());

        $this->getJson('/api/providers')->assertOk()->assertJsonCount(0);
    }
}
