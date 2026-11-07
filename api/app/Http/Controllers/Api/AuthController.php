<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\HealthRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /**
     * Token lifetime per client. Web tokens sit in localStorage on shared
     * clinic desktops, so they live one shift; mobile tokens sit in the
     * device keychain and a patient may not open the app for weeks.
     */
    private const TOKEN_TTL_MINUTES = [
        'web' => 8 * 60,
        'mobile' => 30 * 24 * 60,
    ];

    /**
     * Register a new patient account together with their UDD Health
     * Services record. Admin/personnel accounts are created separately via
     * `php artisan make:staff`.
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'device' => ['nullable', Rule::in(array_keys(self::TOKEN_TTL_MINUTES))],
            ...HealthRecord::rules($request->all()),
        ], HealthRecord::MESSAGES, HealthRecord::ATTRIBUTE_NAMES);

        $user = new User([
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            ...HealthRecord::attributes($validated),
        ]);
        // `role` is deliberately not mass-assignable; set it explicitly.
        $user->role = 'patient';
        $user->save();

        app(AuditLogger::class)->log($user->id, 'auth.registered', $user, [
            'device' => $validated['device'] ?? 'web',
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'user' => $user,
            'token' => $this->issueToken($user, $validated['device'] ?? null),
        ], 201);
    }

    /**
     * Log in an existing user and issue a new API token.
     */
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device' => ['nullable', Rule::in(array_keys(self::TOKEN_TTL_MINUTES))],
        ]);

        $user = User::where('email', $validated['email'])->first();

        // Always run the hash comparison so an unknown email costs the same
        // time as a wrong password — otherwise response timing reveals
        // which emails have accounts.
        $hash = $user?->password ?? self::dummyHash();

        if (! Hash::check($validated['password'], $hash) || ! $user) {
            // Failed attempts are the first thing an audit asks for. The
            // user row is attached when the email exists so a targeted
            // guessing run shows up under that account.
            app(AuditLogger::class)->log($user?->id, 'auth.login_failed', $user, [
                'email' => $validated['email'],
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'The provided credentials are incorrect.',
            ], 422);
        }

        app(AuditLogger::class)->log($user->id, 'auth.login', $user, [
            'device' => $validated['device'] ?? 'web',
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'user' => $user,
            'token' => $this->issueToken($user, $validated['device'] ?? null),
        ]);
    }

    /**
     * Revoke the token used for the current request.
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        app(AuditLogger::class)->log($request->user()->id, 'auth.logout', $request->user(), [
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Logged out.',
        ]);
    }

    /**
     * Return the currently authenticated user.
     */
    public function user(Request $request)
    {
        return response()->json($request->user());
    }

    /**
     * A patient correcting or completing their own health record — from the
     * phone's "My health record" screen, or the booking step that asks them
     * to confirm it. The email doubles as the login and where booking codes
     * go, so it can be changed here too.
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $rules = HealthRecord::rules($request->all(), $user);

        // Once the nurse has checked it against the patient's UDD ID card,
        // the ID number is the clinic's record: a patient who could still
        // change it could swap their checked account onto someone else's.
        if ($user->hasVerifiedSchoolId()) {
            $rules['school_id'] = ['required', 'string', Rule::in([$user->school_id])];
        }

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            ...$rules,
        ], [
            ...HealthRecord::MESSAGES,
            'school_id.in' => 'Your ID number was checked by the clinic, so only the nurse can change it.',
        ], HealthRecord::ATTRIBUTE_NAMES);

        $user->fill([
            'email' => $validated['email'],
            ...HealthRecord::attributes($validated),
        ]);
        $changed = array_keys($user->getDirty());
        $user->save();

        if ($changed !== []) {
            // Field names only: the values are personal data.
            app(AuditLogger::class)->log($user->id, 'profile.updated', $user, ['fields' => $changed]);
        }

        return response()->json($user->fresh());
    }

    /**
     * Store the Expo push token for the current device.
     */
    public function updatePushToken(Request $request)
    {
        $validated = $request->validate([
            'push_token' => ['required', 'string', 'max:255'],
        ]);

        $request->user()->update(['push_token' => $validated['push_token']]);

        return response()->json(['message' => 'Push token saved.']);
    }

    /**
     * Change the current user's password. Every other session is revoked:
     * the reason someone changes a password on a shared clinic desktop is
     * usually that they think another session shouldn't exist.
     */
    public function updatePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password:sanctum'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::defaults()],
        ]);

        $user = $request->user();
        $user->update(['password' => Hash::make($validated['password'])]);

        $user->tokens()
            ->whereKeyNot($user->currentAccessToken()->id)
            ->delete();

        app(AuditLogger::class)->log($user->id, 'auth.password_changed', $user, [
            'ip' => $request->ip(),
        ]);

        return response()->json(['message' => 'Password updated.']);
    }

    /**
     * Unknown clients get the short (web) lifetime.
     */
    private function issueToken(User $user, ?string $device): string
    {
        $ttl = self::TOKEN_TTL_MINUTES[$device ?? 'web'];

        return $user->createToken('auth_token', ['*'], Carbon::now()->addMinutes($ttl))->plainTextToken;
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make('flowcast-timing-equaliser');
    }
}
