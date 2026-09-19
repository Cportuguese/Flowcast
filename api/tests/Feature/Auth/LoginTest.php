<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_credentials_return_user_and_token(): void
    {
        $user = User::factory()->create(['email' => 'nurse@example.com']);

        $this->postJson('/api/login', ['email' => 'nurse@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure(['token'])
            ->assertJsonMissingPath('user.password');
    }

    public function test_wrong_password_and_unknown_email_give_the_same_response(): void
    {
        User::factory()->create(['email' => 'nurse@example.com']);

        $wrongPassword = $this->postJson('/api/login', ['email' => 'nurse@example.com', 'password' => 'nope']);
        $unknownEmail = $this->postJson('/api/login', ['email' => 'ghost@example.com', 'password' => 'nope']);

        $wrongPassword->assertUnprocessable();
        $unknownEmail->assertUnprocessable();
        $this->assertSame($wrongPassword->json(), $unknownEmail->json());
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/login', ['email' => 'ghost@example.com', 'password' => 'nope'])
                ->assertUnprocessable();
        }

        $this->postJson('/api/login', ['email' => 'ghost@example.com', 'password' => 'nope'])
            ->assertStatus(429);
    }

    public function test_requests_without_a_token_are_rejected(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_expired_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t', ['*'], now()->subMinute())->plainTextToken;

        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_live_token_is_accepted(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t', ['*'], now()->addHour())->plainTextToken;

        $this->withToken($token)->getJson('/api/user')->assertOk()->assertJsonPath('id', $user->id);
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $phone = $user->createToken('phone', ['*'], now()->addDay())->plainTextToken;
        $desk = $user->createToken('desk', ['*'], now()->addDay())->plainTextToken;

        $this->withToken($desk)->postJson('/api/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'phone']);

        // The request guard memoises the resolved user for the lifetime of the
        // test app, so drop it to force a real re-read of the token table.
        $this->app['auth']->forgetGuards();
        $this->withToken($desk)->getJson('/api/user')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->withToken($phone)->getJson('/api/user')->assertOk();
    }
}
