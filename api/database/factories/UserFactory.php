<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * A patient with a complete UDD Health Services record by default, so
     * anything that needs one (booking) works without extra setup.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->lastName(),
            'last_name' => fake()->lastName(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // Factories bypass the fillable guard, so setting role here is fine.
            'role' => 'patient',
            'birthdate' => fake()->dateTimeBetween('-24 years', '-17 years')->format('Y-m-d'),
            'sex' => fake()->randomElement(['male', 'female']),
            'home_address' => fake()->streetAddress().', Dagupan City',
            'patient_type' => 'student',
            'department' => 'SITE',
            'course' => 'BSIT',
            'school_id' => fake()->unique()->numerify('2#-####-###'),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function patient(): static
    {
        return $this->state(fn () => ['role' => 'patient']);
    }

    /** The nurse has checked their ID number against their UDD ID card. */
    public function verifiedId(): static
    {
        return $this->state(fn () => ['school_id_verified_at' => now()]);
    }

    public function personnel(): static
    {
        return $this->state(fn () => ['role' => 'personnel', ...self::NO_HEALTH_RECORD]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => 'admin', ...self::NO_HEALTH_RECORD]);
    }

    /** The head nurse: the admin account, and a bookable professional. */
    public function nurse(): static
    {
        return $this->admin()->state(fn () => ['profession' => 'nurse']);
    }

    public function dentist(): static
    {
        return $this->personnel()->state(fn () => ['profession' => 'dentist']);
    }

    public function physician(): static
    {
        return $this->personnel()->state(fn () => ['profession' => 'physician']);
    }

    /** A patient account missing the health record (e.g. an old walk-in). */
    public function withoutHealthRecord(): static
    {
        return $this->state(fn () => self::NO_HEALTH_RECORD);
    }

    /** Staff accounts carry no patient record. */
    private const NO_HEALTH_RECORD = [
        'first_name' => null,
        'middle_name' => null,
        'last_name' => null,
        'birthdate' => null,
        'sex' => null,
        'home_address' => null,
        'patient_type' => null,
        'department' => null,
        'course' => null,
        'school_id' => null,
    ];
}
