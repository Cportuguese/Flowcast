<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/**
 * This is an API-only app with no browser login page. Laravel's default
 * auth middleware redirects guests to a route named "login" when the
 * request doesn't explicitly ask for JSON; without this route that
 * redirect itself throws a RouteNotFoundException (a 500 with a full
 * debug trace) instead of a clean 401.
 */
Route::get('/login', function () {
    return response()->json(['message' => 'Unauthenticated.'], 401);
})->name('login');
