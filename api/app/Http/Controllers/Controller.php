<?php

namespace App\Http\Controllers;

use App\Models\User;

abstract class Controller
{
    /**
     * Staff may read any patient's record; a patient may read only their own.
     *
     * The visit and certificate endpoints are reachable by all three roles,
     * so this distinction cannot be expressed as route middleware and has to
     * be made once the record is in hand.
     */
    protected function abortUnlessStaffOrOwner(User $user, int $patientId): void
    {
        if (! $user->isStaff() && $patientId !== $user->id) {
            abort(403, 'This action is unauthorized.');
        }
    }
}
