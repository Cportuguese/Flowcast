<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Dev logins only — never seed known passwords into a real deployment.
        if (! app()->isProduction()) {
            $this->call(DevUsersSeeder::class);
        }
    }
}
