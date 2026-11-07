<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Udd;

class DepartmentController extends Controller
{
    /**
     * UDD's departments and their courses. Public, because the sign-up form
     * needs it before anyone has an account; it is the school's own public
     * program list, not patient data.
     */
    public function index()
    {
        return response()->json(Udd::catalog());
    }
}
