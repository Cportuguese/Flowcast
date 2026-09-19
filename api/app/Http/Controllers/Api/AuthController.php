<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
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
     * Register a new patient account. Admin/personnel accounts are created
     * separately via `php artisan make:staff`.
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'patient_type' => ['nullable', Rule::in(['student', 'faculty', 'staff'])],
            'contact_no' => ['nullable', 'string', 'max:32'],
            'device' => ['nullable', Rule::in(array_keys(self::TOKEN_TTL_MINUTES))],
        ]);

        $user = new User([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'patient_type' => $validated['patient_type'] ?? null,
            'contact_no' => $validated['contact_no'] ?? null,
        ]);
        // `role` is deliberately not mass-assignable; set it explicitly.
        $user->role = 'patient';
        $user->save();

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
            return response()->json([
                'message' => 'The provided credentials are incorrect.',
            ], 422);
        }

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
