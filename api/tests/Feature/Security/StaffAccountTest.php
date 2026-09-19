<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_is_not_mass_assignable(): void
    {
        $user = User::create([
            'name' => 'Sneaky',
            'email' => 'sneaky@example.com',
            'password' => 'irrelevant',
            'role' => 'admin',
        ]);

        $this->assertSame('patient', $user->fresh()->role);

        $user->fill(['role' => 'admin'])->save();

        $this->assertSame('patient', $user->fresh()->role);
    }

    public function test_make_staff_creates_an_admin(): void
    {
        $this->artisan('make:staff', [
            'email' => 'doc@example.com',
            '--role' => 'admin',
            '--name' => 'Dr. Doc',
            '--password' => 'Secret-Pass-123',
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'doc@example.com', 'role' => 'admin', 'name' => 'Dr. Doc']);

        $this->postJson('/api/login', ['email' => 'doc@example.com', 'password' => 'Secret-Pass-123'])
            ->assertOk()
            ->assertJsonPath('user.role', 'admin');
    }

    public function test_make_staff_defaults_to_personnel_and_generates_a_password(): void
    {
        $this->artisan('make:staff', ['email' => 'nurse@example.com'])
            ->expectsOutputToContain('Generated password:')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'nurse@example.com', 'role' => 'personnel', 'name' => 'nurse']);
    }

    public function test_make_staff_refuses_patient_role_and_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->artisan('make:staff', ['email' => 'taken@example.com'])->assertFailed();
        $this->artisan('make:staff', ['email' => 'new@example.com', '--role' => 'patient'])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }
}
