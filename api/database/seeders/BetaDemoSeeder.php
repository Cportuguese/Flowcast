<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * The sample dataset for the BETA test: three years of clinic history
 * instead of the alpha's six months, in one command:
 *
 *   php artisan migrate:fresh --seed --seeder=BetaDemoSeeder
 *
 * It is AlphaDemoSeeder with the time dial turned up, reusing the same
 * seeders rather than duplicating them:
 *
 * - 1,096 days (three years) of simulated queue, bookings and visits
 * - a few thousand patients rather than 240, each appearing only while
 *   enrolled (a student whose ID says 2025 has no visit in 2023), with
 *   accounts dated to when they enrolled
 * - the medicine inventory delivered about every quarter, with every
 *   dispensing logged against the batch it came from
 * - a modest share of rest-worthy visits ending in a medical certificate
 * - the same known dev logins, today's queue and coming week as the alpha
 *
 * Everything it writes is sample data, not real patients. The history is
 * SYNTHETIC and must be described as simulated wherever it is shown.
 */
class BetaDemoSeeder extends Seeder
{
    /** Three years, counting the leap day. */
    public const DAYS_OF_HISTORY = 1096;

    /** About 9 visits each over three years: some regulars, many occasional. */
    public const PATIENTS = 4000;

    /** Far fewer than the alpha's 35%: three years of them would be a lot. */
    public const CERTIFICATE_SHARE = 0.12;

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('BetaDemoSeeder writes sample patients and known passwords. Refusing to run in production.');

            return;
        }

        // Years of simulated rows are held in memory at a time.
        ini_set('memory_limit', '2G');
        set_time_limit(0);

        $this->call(DevUsersSeeder::class);

        $history = $this->resolve(DemoHistorySeeder::class);
        $history->days = self::DAYS_OF_HISTORY;
        $history->patientCount = self::PATIENTS;
        $history->enrollmentAware = true;
        $history->__invoke();

        // Fit the models on the simulated history alone, before any of the
        // sample layer exists, so their stored holdout metrics describe
        // exactly that history.
        Artisan::call('ml:train');
        $this->command?->line(trim(Artisan::output()));

        $sample = $this->resolve(AlphaSampleDataSeeder::class);
        $sample->dispensingWindowDays = self::DAYS_OF_HISTORY;
        $sample->certificateWindowDays = self::DAYS_OF_HISTORY;
        $sample->certificateShare = self::CERTIFICATE_SHARE;
        $sample->restockHistory = true;
        $sample->__invoke();

        $this->call(AlphaTodaySeeder::class);

        // The demo patient is a second-year BSIT student (ID 24-…): their
        // account dates from when they entered, not from the day of seeding,
        // so none of their visits predate it.
        $registered = Carbon::create(2024, 6, 12, 9, 40);
        User::where('email', DevUsersSeeder::PATIENT_EMAIL)->update([
            'created_at' => $registered,
            'school_id_verified_at' => $registered->copy()->addDays(80),
        ]);
    }
}
