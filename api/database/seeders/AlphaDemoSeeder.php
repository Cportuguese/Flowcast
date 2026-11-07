<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * The full sample dataset for the capstone alpha test, in one command:
 *
 *   php artisan migrate:fresh --seed --seeder=AlphaDemoSeeder
 *
 * It reuses the existing seeders instead of duplicating them: the known
 * dev logins, the SYNTHETIC 182-day history the forecasting models are
 * fitted on, the models themselves, and then the sample layer (names,
 * vitals, medicines, certificates, upcoming bookings) and today's queue.
 * Everything it writes is sample data, not real patients.
 */
class AlphaDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('AlphaDemoSeeder writes sample patients and known passwords. Refusing to run in production.');

            return;
        }

        $this->call(DevUsersSeeder::class);
        $this->call(DemoHistorySeeder::class);

        // Fit the models on the simulated history alone, before any of the
        // sample layer exists, so their stored holdout metrics describe
        // exactly that history.
        Artisan::call('ml:train');
        $this->command?->line(trim(Artisan::output()));

        $this->call(AlphaSampleDataSeeder::class);
        $this->call(AlphaTodaySeeder::class);
    }
}
