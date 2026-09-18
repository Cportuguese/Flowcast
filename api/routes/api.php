<?php

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\QueueController;
use App\Http\Controllers\Api\ScheduleController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:6,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::patch('/user/push-token', [AuthController::class, 'updatePushToken']);

    Route::get('/admin/ping', function () {
        return response()->json(['message' => 'pong from admin route']);
    })->middleware('role:admin');

    Route::get('/personnel/ping', function () {
        return response()->json(['message' => 'pong from personnel route']);
    })->middleware('role:admin,personnel');

    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('/schedules', [ScheduleController::class, 'index']);
        Route::post('/schedules', [ScheduleController::class, 'store']);
        Route::get('/event-days', [ScheduleController::class, 'eventDays']);
        Route::post('/event-days', [ScheduleController::class, 'storeEventDay']);
        Route::delete('/event-days/{eventDay}', [ScheduleController::class, 'destroyEventDay']);
    });

    Route::get('/appointments/available-slots', [AppointmentController::class, 'availableSlots']);

    Route::middleware('role:patient')->group(function () {
        Route::post('/appointments', [AppointmentController::class, 'store']);
        Route::get('/appointments/mine', [AppointmentController::class, 'mine']);
        Route::post('/appointments/{appointment}/cancel', [AppointmentController::class, 'cancel']);
    });

    Route::middleware('role:admin,personnel')->group(function () {
        Route::get('/appointments', [AppointmentController::class, 'forDate']);
    });

    Route::post('/queue/check-in', [QueueController::class, 'checkIn']);

    Route::middleware('role:patient')->group(function () {
        Route::get('/queue/mine', [QueueController::class, 'mine']);
    });

    Route::middleware('role:admin,personnel')->group(function () {
        Route::get('/queue/today', [QueueController::class, 'today']);

        Route::prefix('admin/queue')->group(function () {
            Route::post('/call-next', [QueueController::class, 'callNext']);
            Route::post('/walk-in', [QueueController::class, 'walkIn']);
            Route::patch('/{queueEntry}/complete', [QueueController::class, 'complete']);
            Route::patch('/{queueEntry}/skip', [QueueController::class, 'skip']);
        });
    });
});
