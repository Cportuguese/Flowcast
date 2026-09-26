<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * One known account per role for local development. Idempotent: re-running
 * resets the password and role on the same emails instead of duplicating.
 *
 * Real staff accounts are created with `php artisan make:staff`; this only
 * exists so a fresh local DB is usable without that ceremony.
 */
class DevUsersSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public function run(): void
    {
        // DatabaseSeeder already guards this, but `db:seed --class=DevUsersSeeder`
        // bypasses that — and these are known-password admin accounts.
        if (app()->isProduction()) {
            $this->command?->error('Refusing to seed known dev passwords in production. Use `php artisan make:staff`.');

            return;
        }

        $accounts = [
            [
                'email' => 'admin@flowcast.test',
                'name' => 'Dev Admin',
                'role' => 'admin',
            ],
            [
                'email' => 'personnel@flowcast.test',
                'name' => 'Dev Nurse',
                'role' => 'personnel',
            ],
            [
                'email' => 'patient@flowcast.test',
                'name' => 'Dev Student',
                'role' => 'patient',
                'school_id' => '2024-00001',
                'patient_type' => 'student',
                'contact_no' => '09171234567',
            ],
        ];

        foreach ($accounts as $account) {
            $user = User::firstOrNew(['email' => $account['email']]);
            $user->fill([
                'name' => $account['name'],
                'email' => $account['email'],
                'password' => Hash::make(self::PASSWORD),
                'school_id' => $account['school_id'] ?? null,
                'patient_type' => $account['patient_type'] ?? null,
                'contact_no' => $account['contact_no'] ?? null,
            ]);
            // `role` is not mass-assignable; set it explicitly.
            $user->role = $account['role'];
            $user->email_verified_at ??= now();
            $user->save();

            $this->command?->info("{$user->role}: {$user->email} / ".self::PASSWORD);
        }
    }
}
