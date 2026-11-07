<?php

namespace Database\Seeders;

use App\Models\ClinicSchedule;
use App\Models\ProviderSchedule;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * UDD's real clinic hours, so a fresh database behaves like the clinic:
 *
 * - the clinic (Nurse Portia) is open Monday to Saturday, 8 AM to 6 PM
 * - the dentist is in on Tuesdays and Thursdays, 8 AM to 5 PM
 * - the school physician is in on Fridays, 1 PM to 3 PM
 *
 * Every professional's hours are also editable in Clinic Settings. Only
 * fills in what is missing, so hours an admin changed are kept.
 */
class ClinicSetupSeeder extends Seeder
{
    /** Clinic opening hours (the walk-in queue, the TV board, the forecasts). */
    public const CLINIC_DAYS = [1, 2, 3, 4, 5, 6];

    public const CLINIC_OPENS = '08:00:00';

    public const CLINIC_CLOSES = '18:00:00';

    /**
     * profession => [weekdays, start, end, slot minutes]. One booking per
     * slot, so a confirmed patient's booked time is the time they are seen.
     *
     * @var array<string, array{0: array<int, int>, 1: string, 2: string, 3: int}>
     */
    public const BOOKING_HOURS = [
        'nurse' => [self::CLINIC_DAYS, '08:00:00', '18:00:00', 20],
        'dentist' => [[2, 4], '08:00:00', '17:00:00', 30],
        'physician' => [[5], '13:00:00', '15:00:00', 20],
    ];

    public function run(): void
    {
        foreach (self::CLINIC_DAYS as $day) {
            ClinicSchedule::firstOrCreate(['day_of_week' => $day], [
                'start_time' => self::CLINIC_OPENS,
                'end_time' => self::CLINIC_CLOSES,
                'slot_minutes' => 20,
                'capacity' => 1,
                'is_active' => true,
            ]);
        }

        foreach (User::providers()->get() as $provider) {
            [$days, $start, $end, $slotMinutes] = self::BOOKING_HOURS[$provider->profession];

            foreach ($days as $day) {
                ProviderSchedule::firstOrCreate(['user_id' => $provider->id, 'day_of_week' => $day], [
                    'start_time' => $start,
                    'end_time' => $end,
                    'slot_minutes' => $slotMinutes,
                    'capacity' => 1,
                    'is_active' => true,
                ]);
            }
        }
    }
}
