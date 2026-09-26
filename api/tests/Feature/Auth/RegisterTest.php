<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Pat Patient',
            'email' => 'pat@example.com',
            'password' => 'Secret-Pass-123',
            'password_confirmation' => 'Secret-Pass-123',
        ], $overrides);
    }

    public function test_registration_creates_a_patient_and_returns_a_token(): void
    {
        $response = $this->postJson('/api/register', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('user.role', 'patient')
            ->assertJsonStructure(['token'])
            ->assertJsonMissingPath('user.password');

        $this->assertDatabaseHas('users', ['email' => 'pat@example.com', 'role' => 'patient']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.registered', 'model_type' => 'User']);
    }

    public function test_registration_ignores_a_submitted_role(): void
    {
        $this->postJson('/api/register', $this->payload(['role' => 'admin']))->assertCreated();

        $this->assertSame('patient', User::where('email', 'pat@example.com')->value('role'));
    }

    public function test_registration_rejects_duplicate_email_and_weak_password(): void
    {
        User::factory()->create(['email' => 'pat@example.com']);

        $this->postJson('/api/register', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->postJson('/api/register', $this->payload([
            'email' => 'other@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['password']);
    }

    public function test_web_token_expires_in_eight_hours_by_default(): void
    {
        $this->freezeTime();

        $this->postJson('/api/register', $this->payload())->assertCreated();

        $token = User::where('email', 'pat@example.com')->first()->tokens()->first();

        $this->assertSame(now()->addHours(8)->timestamp, $token->expires_at->timestamp);
    }

    public function test_mobile_token_expires_in_thirty_days(): void
    {
        $this->freezeTime();

        $this->postJson('/api/register', $this->payload(['device' => 'mobile']))->assertCreated();

        $token = User::where('email', 'pat@example.com')->first()->tokens()->first();

        $this->assertSame(now()->addDays(30)->timestamp, $token->expires_at->timestamp);
    }

    public function test_unknown_device_is_rejected(): void
    {
        $this->postJson('/api/register', $this->payload(['device' => 'toaster']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['device']);
    }
}
