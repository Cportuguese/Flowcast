<?php

use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CertificateController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\MedicineController;
use App\Http\Controllers\Api\PatientIdentityController;
use App\Http\Controllers\Api\ProviderController;
use App\Http\Controllers\Api\QueueController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\VisitController;
use Illuminate\Support\Facades\Route;

/*
| Who is who: `admin` is the head nurse (Nurse Portia), who runs the queue
| and oversees everything; `personnel` are the dentist and the school
| physician, who see only their own bookings and the records they write;
| `patient` is everyone the clinic serves (through the mobile app).
*/

// Keyed by (email, IP) so one crowded network can't lock everyone out.
Route::middleware('throttle:auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('throttle:public')->group(function () {
    // Feeds the waiting-room TV board. No patient data.
    Route::get('/queue-board/today', [QueueController::class, 'boardToday']);
    // UDD's public course list, which the sign-up form needs before login.
    Route::get('/departments', [DepartmentController::class, 'index']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::patch('/user/push-token', [AuthController::class, 'updatePushToken']);
    // Verifies the current password, so it gets its own tight limiter.
    Route::patch('/user/password', [AuthController::class, 'updatePassword'])
        ->middleware('throttle:sensitive');
    Route::patch('/user/profile', [AuthController::class, 'updateProfile'])
        ->middleware('role:patient');

    Route::middleware('role:admin')->prefix('admin')->group(function () {
        // The clinic's own opening hours (queue, TV board, forecasts).
        Route::get('/schedules', [ScheduleController::class, 'index']);
        Route::post('/schedules', [ScheduleController::class, 'store']);
        Route::get('/event-days', [ScheduleController::class, 'eventDays']);
        Route::post('/event-days', [ScheduleController::class, 'storeEventDay']);
        Route::delete('/event-days/{eventDay}', [ScheduleController::class, 'destroyEventDay']);

        // When each professional can be booked.
        Route::get('/providers', [ProviderController::class, 'adminIndex']);
        Route::post('/providers/{user}/schedules', [ProviderController::class, 'storeSchedule']);

        // Checking a patient's self-entered ID number against their UDD card.
        Route::patch('/patients/{user}/verify-id', [PatientIdentityController::class, 'verify']);
        Route::patch('/patients/{user}/release-id', [PatientIdentityController::class, 'release']);

        Route::get('/audit-logs', [AuditLogController::class, 'index']);

        Route::prefix('reports')->group(function () {
            Route::get('/queue-summary', [ReportController::class, 'queueSummary']);
            Route::get('/visit-summary', [ReportController::class, 'visitSummary']);
            Route::get('/no-show-summary', [ReportController::class, 'noShowSummary']);
            Route::get('/peak-hours-by-weekday', [ReportController::class, 'peakHoursByWeekday']);
        });
    });

    // Booking: who can be seen, and when.
    Route::get('/providers', [ProviderController::class, 'index']);
    Route::get('/appointments/calendar', [AppointmentController::class, 'calendar']);
    Route::get('/appointments/available-slots', [AppointmentController::class, 'availableSlots']);

    Route::middleware('role:patient')->group(function () {
        // Both steps check a one-time code, so both get the tight limiter.
        Route::middleware('throttle:sensitive')->group(function () {
            Route::post('/appointments/otp', [AppointmentController::class, 'requestOtp']);
            Route::post('/appointments', [AppointmentController::class, 'store']);
        });
        Route::get('/appointments/mine', [AppointmentController::class, 'mine']);
        Route::post('/appointments/{appointment}/cancel', [AppointmentController::class, 'cancel']);
    });

    // The head nurse sees every booking; the dentist and physician their own.
    Route::middleware('role:admin,personnel')->group(function () {
        Route::get('/appointments', [AppointmentController::class, 'index']);
        Route::patch('/appointments/{appointment}/no-show', [AppointmentController::class, 'markNoShow']);
    });

    // Only the head nurse reviews bookings.
    Route::middleware('role:admin')->group(function () {
        Route::patch('/appointments/{appointment}/approve', [AppointmentController::class, 'approve']);
        Route::patch('/appointments/{appointment}/decline', [AppointmentController::class, 'decline']);
    });

    // A patient checks themselves in; the head nurse checks in others.
    Route::post('/queue/check-in', [QueueController::class, 'checkIn'])
        ->middleware('role:admin,patient');

    Route::middleware('role:patient')->group(function () {
        Route::get('/queue/mine', [QueueController::class, 'mine']);
    });

    // The walk-in queue is the head nurse's.
    Route::middleware('role:admin')->group(function () {
        Route::get('/queue/today', [QueueController::class, 'today']);

        Route::prefix('admin/queue')->group(function () {
            Route::post('/call-next', [QueueController::class, 'callNext']);
            Route::post('/walk-in', [QueueController::class, 'walkIn']);
            Route::get('/lookup', [QueueController::class, 'lookupPatient']);
            Route::patch('/{queueEntry}/complete', [QueueController::class, 'complete']);
            Route::patch('/{queueEntry}/skip', [QueueController::class, 'skip']);
        });

        Route::get('/queue/{date}/history', [QueueController::class, 'history'])
            ->where('date', '\d{4}-\d{2}-\d{2}');
    });

    Route::middleware('role:admin,personnel')->group(function () {
        Route::post('/visits', [VisitController::class, 'store']);
        Route::get('/admin/visits/search', [VisitController::class, 'search']);
    });

    Route::middleware('role:patient')->group(function () {
        Route::get('/visits/mine', [VisitController::class, 'mine']);
    });

    Route::get('/visits/{visit}', [VisitController::class, 'show']);

    // Medical certificates — any of the three professionals issues them
    // (the dentist and physician only for visits they recorded, checked
    // in-controller). Patients get theirs from the clinic: they can neither
    // issue, view nor download one through the app.
    Route::middleware('role:admin,personnel')->group(function () {
        Route::post('/visits/{visit}/certificate', [CertificateController::class, 'store']);
        Route::get('/visits/{visit}/certificate', [CertificateController::class, 'show']);
        Route::get('/visits/{visit}/certificate/download', [CertificateController::class, 'download']);
    });

    // Inventory. The dentist and physician only need the medicine list for
    // what they dispense in a visit record; the stock itself is the head
    // nurse's.
    Route::get('/medicines', [MedicineController::class, 'index'])
        ->middleware('role:admin,personnel');

    Route::middleware('role:admin')->group(function () {
        Route::post('/inventory/stock-in', [InventoryController::class, 'stockIn']);
        Route::post('/inventory/stock-out', [InventoryController::class, 'stockOut']);
        Route::get('/inventory/low-stock', [InventoryController::class, 'lowStock']);
        Route::get('/inventory/expiring-soon', [InventoryController::class, 'expiringSoon']);

        Route::prefix('admin')->group(function () {
            Route::post('/medicines', [MedicineController::class, 'store']);
            Route::patch('/medicines/{medicine}', [MedicineController::class, 'update']);
            Route::get('/inventory/expired', [InventoryController::class, 'expired']);
            Route::post('/inventory/batches/{batch}/dispose', [InventoryController::class, 'disposeBatch']);
            Route::get('/inventory/history', [InventoryController::class, 'history']);
        });
    });

    // Analytics are the head nurse's: she oversees the clinic.
    Route::middleware('role:admin')->prefix('analytics')->group(function () {
        Route::get('/dashboard', [AnalyticsController::class, 'dashboard']);
        Route::get('/volume-forecast', [AnalyticsController::class, 'volumeForecast']);
        Route::get('/wait-time-forecast', [AnalyticsController::class, 'waitTimeForecast']);
        Route::get('/peak-hours', [AnalyticsController::class, 'peakHours']);
        Route::get('/breakdown', [AnalyticsController::class, 'breakdown']);
        Route::get('/model-performance', [AnalyticsController::class, 'modelPerformance']);
    });
});
