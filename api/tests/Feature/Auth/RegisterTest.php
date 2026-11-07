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
            'first_name' => 'Juan',
            'middle_name' => 'Santiago',
            'last_name' => 'Dela Cruz',
            'birthdate' => '2004-03-04',
            'sex' => 'male',
            'home_address' => '12 Arellano St., Dagupan City',
            'patient_type' => 'student',
            'course' => 'BSIT',
            'school_id' => '23-1057-651',
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

    public function test_registration_saves_the_health_record_and_builds_the_name(): void
    {
        $this->postJson('/api/register', $this->payload())->assertCreated();

        $user = User::where('email', 'pat@example.com')->first();

        $this->assertSame('Juan Santiago Dela Cruz', $user->name);
        $this->assertSame('2004-03-04', $user->birthdate->toDateString());
        $this->assertSame('23-1057-651', $user->school_id);
        $this->assertSame('BSIT', $user->course);
        // The department follows from the course.
        $this->assertSame('SITE', $user->department);
        $this->assertTrue($user->hasCompleteHealthRecord());
    }

    public function test_a_middle_initial_is_not_a_middle_name(): void
    {
        foreach (['S', 'S.', 'S. R.'] as $initial) {
            $this->postJson('/api/register', $this->payload(['middle_name' => $initial]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['middle_name']);
        }

        $this->postJson('/api/register', $this->payload(['middle_name' => 'Sta. Maria']))->assertCreated();
    }

    public function test_someone_without_a_middle_name_can_say_so(): void
    {
        $this->postJson('/api/register', $this->payload(['middle_name' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['middle_name']);

        $this->postJson('/api/register', $this->payload(['middle_name' => null, 'no_middle_name' => true]))
            ->assertCreated();

        $this->assertSame('Juan Dela Cruz', User::where('email', 'pat@example.com')->value('name'));
    }

    public function test_student_ids_must_follow_the_udd_format_and_be_unique(): void
    {
        foreach (['2310576510', '23-1057-65', 'ABC-1234-567'] as $badId) {
            $this->postJson('/api/register', $this->payload(['school_id' => $badId]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['school_id']);
        }

        User::factory()->create(['school_id' => '23-1057-651']);

        $this->postJson('/api/register', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_id']);
    }

    public function test_faculty_pick_a_department_and_have_no_course(): void
    {
        $this->postJson('/api/register', $this->payload(['patient_type' => 'faculty', 'course' => null, 'school_id' => 'FAC-0042']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['department']);

        $this->postJson('/api/register', $this->payload([
            'patient_type' => 'faculty',
            'course' => 'BSIT',
            'department' => 'STE',
            'school_id' => 'FAC-0042',
        ]))->assertCreated();

        $user = User::where('email', 'pat@example.com')->first();
        $this->assertSame('STE', $user->department);
        $this->assertNull($user->course);
    }

    public function test_names_typed_in_one_case_are_tidied(): void
    {
        $this->postJson('/api/register', $this->payload(['first_name' => 'juan', 'middle_name' => 'SANTIAGO', 'last_name' => 'dela Cruz']))
            ->assertCreated();

        // All-lower and all-upper are fixed; mixed case is kept as typed.
        $this->assertSame('Juan Santiago dela Cruz', User::where('email', 'pat@example.com')->value('name'));
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
