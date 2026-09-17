<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:6,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    Route::get('/admin/ping', function () {
        return response()->json(['message' => 'pong from admin route']);
    })->middleware('role:admin');

    Route::get('/personnel/ping', function () {
        return response()->json(['message' => 'pong from personnel route']);
    })->middleware('role:admin,personnel');
});
