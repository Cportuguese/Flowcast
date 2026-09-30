<?php

namespace Tests\Feature\Queue;

use App\Models\ClinicSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BoardHoursTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * The board compares now()'s wall clock against schedule rows that hold
     * plain local times. Carbon::setTestNow() below is interpreted in the app
     * timezone, so every test in this file passes on UTC too — this is the
     * assertion that catches the config drifting back and the board reporting
     * "closed" through the whole clinic morning.
     */
    public function test_app_runs_on_the_clinics_own_timezone(): void
    {
        $this->assertSame('Asia/Manila', config('app.timezone'));
    }

    public function test_board_reports_open_during_todays_hours(): void
    {
        Carbon::setTestNow('2026-09-21 10:00:00'); // a Monday
        ClinicSchedule::factory()->create(['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '17:00']);

        $this->getJson('/api/queue-board/today')
            ->assertOk()
            ->assertJsonPath('hours.is_open', true)
            ->assertJsonPath('hours.today.start_time', '08:00')
            ->assertJsonPath('hours.today.end_time', '17:00')
            ->assertJsonPath('hours.next_opening', null);
    }

    public function test_board_reports_next_opening_when_closed(): void
    {
        Carbon::setTestNow('2026-09-21 18:30:00'); // Monday, after close
        ClinicSchedule::factory()->create(['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '17:00']);
        ClinicSchedule::factory()->create(['day_of_week' => 3, 'start_time' => '09:00', 'end_time' => '12:00']);

        $this->getJson('/api/queue-board/today')
            ->assertOk()
            ->assertJsonPath('hours.is_open', false)
            ->assertJsonPath('hours.next_opening.day', 'Wednesday')
            ->assertJsonPath('hours.next_opening.start_time', '09:00');
    }

    public function test_board_points_to_today_before_opening(): void
    {
        Carbon::setTestNow('2026-09-21 07:00:00');
        ClinicSchedule::factory()->create(['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '17:00']);

        $this->getJson('/api/queue-board/today')
            ->assertOk()
            ->assertJsonPath('hours.is_open', false)
            ->assertJsonPath('hours.next_opening.date', '2026-09-21');
    }

    public function test_board_handles_no_schedule(): void
    {
        $this->getJson('/api/queue-board/today')
            ->assertOk()
            ->assertJsonPath('hours.is_open', false)
            ->assertJsonPath('hours.today', null)
            ->assertJsonPath('hours.next_opening', null);
    }
}
