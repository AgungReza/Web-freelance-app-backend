<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\JobTypeController;
use App\Http\Controllers\Api\JobPackageController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\GeneralExpenseController;
use App\Http\Controllers\Api\ClientExpenseController;
use App\Http\Controllers\Api\AddOnController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\TaxController;
use App\Http\Controllers\Api\PublicBookingController;
use App\Http\Controllers\Api\PasswordResetController;

/*
|--------------------------------------------------------------------------
| Public Routes — tidak butuh autentikasi
|--------------------------------------------------------------------------
*/

// Throttle ketat untuk endpoint sensitif (5 request per menit per IP)
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword']);
    Route::post('/resend-otp',      [PasswordResetController::class, 'resendOtp']);
});

// Throttle lebih longgar untuk verify & reset (10 request per menit)
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/verify-otp',    [PasswordResetController::class, 'verifyOtp']);
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);
});

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/login',    [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::get('/tax/ptkp-list', [TaxController::class, 'getPtkpList']);

/*
 | Public Booking Routes
 | Semua route di sini diisolasi per user_code.
 | Tidak ada data yang bisa diakses lintas user_code.
 |
 | GET  /public/{user_code}                      → info pemilik form
 | GET  /public/{user_code}/job-types            → list jenis layanan milik user_code tsb
 | GET  /public/{user_code}/packages/{jobTypeId} → list paket milik job type tsb (divalidasi milik user_code)
 | POST /public/booking/{user_code}              → submit booking baru
 */
Route::prefix('public')->group(function () {
    // Info pemilik form
    Route::get('/{user_code}', [PublicBookingController::class, 'hello']);

    // Data untuk mengisi form — diisolasi per user_code
    Route::get('/{user_code}/job-types', [PublicBookingController::class, 'jobTypes']);
    Route::get('/{user_code}/packages/{jobTypeId}', [PublicBookingController::class, 'packages']);

    // Submit booking
    Route::post('/booking/{user_code}', [PublicBookingController::class, 'store']);

    Route::get('/{user_code}/booking/search', [PublicBookingController::class, 'search']);
    Route::get('/{user_code}/booking/{booking_code}', [PublicBookingController::class, 'show']);
    Route::post('/{user_code}/booking/{booking_code}/payment', [PublicBookingController::class, 'addPayment']);
});

/*
|--------------------------------------------------------------------------
| Protected Routes — wajib autentikasi via Sanctum
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    // -- Auth --
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me',      [AuthController::class, 'me']);

    // -- Job Types --
    Route::apiResource('job-types', JobTypeController::class);
    Route::get('job-types/{jobType}/packages', [JobTypeController::class, 'packages'])
        ->name('job-types.packages');

    // -- Job Packages --
    Route::apiResource('job-packages', JobPackageController::class);

    // -- Bookings --
    Route::get('bookings/search', [BookingController::class, 'search']);
    Route::apiResource('bookings', BookingController::class);

    // -- Payments --
    Route::post('bookings/{bookingId}/payments', [PaymentController::class, 'store']);

    // -- General Expenses --
    Route::get('general-expenses',          [GeneralExpenseController::class, 'index']);
    Route::post('general-expenses',         [GeneralExpenseController::class, 'store']);
    Route::put('/general-expenses/{id}',    [GeneralExpenseController::class, 'update']);
    Route::delete('general-expenses/{expense}', [GeneralExpenseController::class, 'destroy']);

    // -- Client Expenses --
    Route::get('bookings/{booking}/expenses',                          [ClientExpenseController::class, 'index']);
    Route::post('bookings/{booking}/expenses',                         [ClientExpenseController::class, 'store']);
    Route::delete('bookings/{booking}/expenses/{expense}',             [ClientExpenseController::class, 'destroy']);

    // -- Add-on per booking --
    Route::post('bookings/{bookingId}/addons',              [AddOnController::class, 'store']);
    Route::delete('bookings/{bookingId}/addons/{addonId}',  [AddOnController::class, 'destroy']);

    // -- Finance Summary --
    Route::get('/finance/monthly-summary', [FinanceController::class, 'monthlySummary']);
    Route::get('/finance/kpi',             [FinanceController::class, 'kpi']);
    Route::get('/finance/charts',          [FinanceController::class, 'charts']);
    Route::get('/finance/activity',        [FinanceController::class, 'activity']);

    // -- Dashboard --
    Route::get('/dashboard/today',   [DashboardController::class, 'today']);
    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);

    // -- Profil Pajak --
    Route::prefix('tax')->group(function () {
        Route::get('/profile',            [TaxController::class, 'getProfile']);
        Route::put('/profile',            [TaxController::class, 'updateProfile']);
        Route::get('/calculate/{year}',   [TaxController::class, 'calculate']);
        Route::post('/save/{year}',       [TaxController::class, 'save']);
        Route::put('/credit/{year}',      [TaxController::class, 'updateCredit']);
        Route::get('/history',            [TaxController::class, 'history']);
        Route::get('/summary',            [TaxController::class, 'summary']);
        Route::delete('/{year}',          [TaxController::class, 'destroy']);
    });
});