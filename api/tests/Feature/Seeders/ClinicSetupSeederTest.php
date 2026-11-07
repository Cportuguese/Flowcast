<?php

namespace Tests\Feature\Seeders;

use App\Models\ClinicSchedule;
use App\Models\ProviderSchedule;
use App\Models\User;
use Database\Seeders\DevUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A fresh database behaves like UDD's clinic: three professionals, each
 * bookable on their real days.
 */
class ClinicSetupSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dev_accounts_are_the_clinics_three_professionals(): void
    {
        $this->seed(DevUsersSeeder::class);

        $portia = User::where('email', DevUsersSeeder::NURSE_EMAIL)->sole();
        $this->assertSame(['admin', 'nurse', 'Nurse Portia', 'Head Nurse'], [$portia->role, $portia->profession, $portia->display_name, $portia->job_title]);

        $this->assertSame('personnel', User::where('email', DevUsersSeeder::DENTIST_EMAIL)->value('role'));
        $this->assertSame('Dr. Elena Santos', User::where('email', DevUsersSeeder::PHYSICIAN_EMAIL)->sole()->display_name);

        $patient = User::where('email', DevUsersSeeder::PATIENT_EMAIL)->sole();
        $this->assertSame('Juan Miguel Santiago Ramos', $patient->name);
        $this->assertTrue($patient->hasCompleteHealthRecord());
    }

    public function test_each_professional_is_bookable_on_their_real_days(): void
    {
        $this->seed(DevUsersSeeder::class);

        $hours = fn (string $profession) => ProviderSchedule::whereIn('user_id', User::where('profession', $profession)->select('id'))
            ->orderBy('day_of_week')
            ->get()
            ->map(fn (ProviderSchedule $schedule) => $schedule->day_of_week.' '.substr($schedule->start_time, 0, 5).'-'.substr($schedule->end_time, 0, 5))
            ->all();

        $this->assertSame(['1 08:00-18:00', '2 08:00-18:00', '3 08:00-18:00', '4 08:00-18:00', '5 08:00-18:00', '6 08:00-18:00'], $hours('nurse'));
        $this->assertSame(['2 08:00-17:00', '4 08:00-17:00'], $hours('dentist'));
        $this->assertSame(['5 13:00-15:00'], $hours('physician'));

        // The clinic itself opens Monday to Saturday, 8 to 6.
        $this->assertSame([1, 2, 3, 4, 5, 6], ClinicSchedule::orderBy('day_of_week')->pluck('day_of_week')->all());
    }

    public function test_hours_changed_in_clinic_settings_survive_a_reseed(): void
    {
        $this->seed(DevUsersSeeder::class);
        $dentist = User::where('profession', 'dentist')->sole();
        ProviderSchedule::where('user_id', $dentist->id)->where('day_of_week', 2)->update(['end_time' => '12:00:00']);

        $this->seed(DevUsersSeeder::class);

        $this->assertSame('12:00:00', ProviderSchedule::where('user_id', $dentist->id)->where('day_of_week', 2)->value('end_time'));
    }
}
