<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

/**
 * The head nurse's two answers when a patient's ID number comes up at the
 * counter. Patients type their own ID number when they sign up, so it is
 * only a claim until she compares it with their UDD ID card:
 *
 * - verify:  the card matches, so the number is the clinic's record from
 *            now on (the patient can no longer change it from the app);
 * - release: the account holding the number is not the person at the
 *            counter, so the number is taken off it and the real owner can
 *            be registered with it.
 */
class PatientIdentityController extends Controller
{
    public function verify(Request $request, User $user)
    {
        $this->ensurePatientWithId($user);

        $user->markSchoolIdVerified($request->user());
        $user->save();

        app(AuditLogger::class)->log($request->user()->id, 'patient.id_verified', $user, [
            'school_id' => $user->school_id,
        ]);

        return response()->json($this->format($user));
    }

    public function release(Request $request, User $user)
    {
        $this->ensurePatientWithId($user);

        $released = $user->school_id;

        // Clearing the number also clears any check of it (see User::booted),
        // and leaves the account's health record incomplete, so it cannot
        // book again until it gives an ID number of its own.
        $user->school_id = null;
        $user->save();

        app(AuditLogger::class)->log($request->user()->id, 'patient.id_released', $user, [
            'school_id' => $released,
        ]);

        return response()->json($this->format($user));
    }

    private function ensurePatientWithId(User $user): void
    {
        if ($user->role !== 'patient') {
            abort(404);
        }

        if ($user->school_id === null) {
            abort(response()->json(['message' => 'This patient has no ID number on file.'], 422));
        }
    }

    /**
     * @return array{id: int, school_id: string|null, id_verified: bool}
     */
    private function format(User $user): array
    {
        return [
            'id' => $user->id,
            'school_id' => $user->school_id,
            'id_verified' => $user->hasVerifiedSchoolId(),
        ];
    }
}
