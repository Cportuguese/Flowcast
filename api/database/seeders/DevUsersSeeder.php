<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * One known account per person the clinic actually has, for local
 * development and demos. Idempotent: re-running resets the password, role
 * and profession on the same emails instead of duplicating.
 *
 * UDD's clinic has three professionals: the head nurse (the admin account)
 * and a dentist and a school physician. The dentist's and physician's names
 * are invented samples.
 *
 * Real staff accounts are created with `php artisan make:staff`; this only
 * exists so a fresh local DB is usable without that ceremony.
 */
class DevUsersSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public const NURSE_EMAIL = 'admin@flowcast.test';

    public const DENTIST_EMAIL = 'dentist@flowcast.test';

    public const PHYSICIAN_EMAIL = 'doctor@flowcast.test';

    public const PATIENT_EMAIL = 'patient@flowcast.test';

    public function run(): void
    {
        // DatabaseSeeder already guards this, but `db:seed --class=DevUsersSeeder`
        // bypasses that — and these are known-password admin accounts.
        if (app()->isProduction()) {
            $this->command?->error('Refusing to seed known dev passwords in production. Use `php artisan make:staff`.');

            return;
        }

        $accounts = [
            ['email' => self::NURSE_EMAIL, 'name' => 'Portia', 'role' => 'admin', 'profession' => 'nurse'],
            ['email' => self::DENTIST_EMAIL, 'name' => 'Marco Villanueva', 'role' => 'personnel', 'profession' => 'dentist'],
            ['email' => self::PHYSICIAN_EMAIL, 'name' => 'Elena Santos', 'role' => 'personnel', 'profession' => 'physician'],
            [
                'email' => self::PATIENT_EMAIL,
                'role' => 'patient',
                'profession' => null,
                'first_name' => 'Juan Miguel',
                'middle_name' => 'Santiago',
                'last_name' => 'Ramos',
                'birthdate' => '2005-02-14',
                'sex' => 'male',
                'home_address' => '45 Perez Blvd., Dagupan City, Pangasinan',
                'school_id' => '24-0213-118',
                'patient_type' => 'student',
                'department' => 'SITE',
                'course' => 'BSIT',
                'contact_no' => '09171234567',
            ],
        ];

        foreach ($accounts as $account) {
            $user = User::firstOrNew(['email' => $account['email']]);
            $user->fill([
                'password' => Hash::make(self::PASSWORD),
                'first_name' => $account['first_name'] ?? null,
                'middle_name' => $account['middle_name'] ?? null,
                'last_name' => $account['last_name'] ?? null,
                // Set outright: DatabaseSeeder runs without model events, so
                // the model's own name-building cannot be relied on here.
                'name' => $account['name'] ?? User::joinName($account['first_name'], $account['middle_name'], $account['last_name']),
                'birthdate' => $account['birthdate'] ?? null,
                'sex' => $account['sex'] ?? null,
                'home_address' => $account['home_address'] ?? null,
                'school_id' => $account['school_id'] ?? null,
                'patient_type' => $account['patient_type'] ?? null,
                'department' => $account['department'] ?? null,
                'course' => $account['course'] ?? null,
                'contact_no' => $account['contact_no'] ?? null,
            ]);
            // `role` and `profession` are not mass-assignable; set them explicitly.
            $user->role = $account['role'];
            $user->profession = $account['profession'];
            $user->email_verified_at ??= now();
            // The demo patient is a returning patient: the nurse checked their
            // UDD ID card on their first visit.
            if ($user->role === 'patient' && $user->school_id !== null) {
                $user->school_id_verified_at ??= now();
                $user->school_id_verified_by_id ??= User::where('email', self::NURSE_EMAIL)->value('id');
            }
            $user->save();

            $this->command?->info("{$user->role}: {$user->email} / ".self::PASSWORD." ({$user->display_name})");
        }

        $this->call(ClinicSetupSeeder::class);
    }
}
