<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_change_password_with_current_one(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/user/password', [
            'current_password' => 'password',
            'password' => 'new-secret-123',
            'password_confirmation' => 'new-secret-123',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-secret-123', $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'auth.password_changed',
        ]);
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/user/password', [
            'current_password' => 'nope',
            'password' => 'new-secret-123',
            'password_confirmation' => 'new-secret-123',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        $this->assertSame(0, AuditLog::where('action', 'auth.password_changed')->count());
    }

    public function test_current_password_guessing_is_rate_limited(): void
    {
        config(['security.rate_limits.sensitive' => 3]);
        Sanctum::actingAs(User::factory()->create());

        $attempt = fn () => $this->patchJson('/api/user/password', [
            'current_password' => 'nope',
            'password' => 'new-secret-123',
            'password_confirmation' => 'new-secret-123',
        ]);

        for ($i = 0; $i < 3; $i++) {
            $attempt()->assertUnprocessable();
        }

        $attempt()->assertStatus(429);
    }

    public function test_new_password_must_be_confirmed_and_different(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->patchJson('/api/user/password', [
            'current_password' => 'password',
            'password' => 'new-secret-123',
            'password_confirmation' => 'mismatch',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->patchJson('/api/user/password', [
            'current_password' => 'password',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_other_sessions_are_revoked_but_the_current_one_survives(): void
    {
        $user = User::factory()->create();
        $phone = $user->createToken('auth_token')->plainTextToken;
        $desktop = $user->createToken('auth_token')->plainTextToken;

        $this->withToken($desktop)->patchJson('/api/user/password', [
            'current_password' => 'password',
            'password' => 'new-secret-123',
            'password_confirmation' => 'new-secret-123',
        ])->assertOk();

        $this->assertSame(1, $user->tokens()->count());

        // The guard caches the resolved user for the life of the test app;
        // forget it so each request below authenticates from scratch.
        $this->app['auth']->forgetGuards();
        $this->withToken($desktop)->getJson('/api/user')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($phone)->getJson('/api/user')->assertUnauthorized();
    }
}
