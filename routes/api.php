<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\ProductionController;
use App\Http\Controllers\ProductionTransferController;
use App\Http\Controllers\ReferenceDataController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:6,1')
    ->name('login');

/*
|--------------------------------------------------------------------------
| Password reset (OTP)
|--------------------------------------------------------------------------
|
| Public by necessity, so both endpoints are rate limited. The verify endpoint
| additionally burns the code after a few wrong guesses.
|
*/

Route::post('/password/forgot', [PasswordResetController::class, 'sendOtp'])
    ->middleware('throttle:5,10')
    ->name('password.forgot');

Route::post('/password/reset', [PasswordResetController::class, 'verifyAndReset'])
    ->middleware('throttle:10,10')
    ->name('password.reset');

// Product images are private files, but the signed URL returned in the
// authenticated product response must also work as a direct browser image URL.
Route::get('/productions/{production}/images/{index}', [ProductionController::class, 'image'])
    ->middleware('signed')
    ->whereNumber('index')
    ->name('productions.image');

Route::get('/employees/{employee}/id-card', [EmployeeController::class, 'idCard'])
    ->middleware('signed')
    ->name('employees.id-card');

Route::get('/employees/{employee}/cv', [EmployeeController::class, 'cv'])
    ->middleware('signed')
    ->name('employees.cv');

Route::get('/productions/{production}/guide-file', [ProductionController::class, 'guideFile'])
    ->middleware('signed')
    ->name('productions.guide-file');

Route::get('/productions/{production}/guide-files/{index}', [ProductionController::class, 'guideFile'])
    ->middleware('signed')
    ->whereNumber('index')
    ->name('productions.guide-file-at');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/user', [AuthController::class, 'me'])->name('user');

    // Re-authenticates before changing credentials, so it is throttled like login.
    Route::post('/password/change', [AuthController::class, 'changePassword'])
        ->middleware('throttle:6,1')
        ->name('password.change');

    /*
    |----------------------------------------------------------------------
    | Employee self-service. No role gate and no employee id in any path:
    | the subject is always the staff record linked to the caller's token.
    |----------------------------------------------------------------------
    */
    Route::prefix('portal')->name('portal.')->group(function () {
        Route::get('/summary', [PortalController::class, 'summary'])->name('summary');
        Route::get('/profile', [PortalController::class, 'profile'])->name('profile');
        Route::get('/attendance', [PortalController::class, 'attendance'])->name('attendance');
        Route::get('/id-card', [PortalController::class, 'idCard'])->name('id-card');
        Route::get('/cv', [PortalController::class, 'cv'])->name('cv');
    });

    /*
    |----------------------------------------------------------------------
    | Employees — HR and Admin.
    |----------------------------------------------------------------------
    */
    Route::middleware('role:Admin,HR')->group(function () {
        Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');

        // Before /employees/{employee}: a literal segment must not be read as
        // a model key. Rewrites staff records, so the controller
        // re-authenticates unless the call is a dry run.
        Route::post('/employees/import', [EmployeeController::class, 'import'])
            ->name('employees.import');

        Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');
        Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
        Route::patch('/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');

        Route::get('/employees/{employee}/report', [EmployeeController::class, 'report'])->name('employees.report');

        // Issues credentials for another person: Admin only, password confirmed.
        Route::post('/employees/{employee}/portal-account', [EmployeeController::class, 'createPortalAccount'])
            ->name('employees.portal-account');

        // Sensitive: the controller re-authenticates the caller (password in
        // the request body) and a policy limits deletion to Admin.
        //
        // POST rather than DELETE: the password travels in the body, and DELETE
        // bodies are unevenly supported by HTTP clients and proxies.
        Route::post('/employees/{employee}/delete', [EmployeeController::class, 'destroy'])
            ->name('employees.destroy');
    });

    /*
    |----------------------------------------------------------------------
    | Attendance — biometric import and search. HR owns attendance, Admin
    | sees everything.
    |----------------------------------------------------------------------
    */
    Route::middleware('role:Admin,HR')->group(function () {
        Route::get('/attendance/search', [AttendanceController::class, 'search'])
            ->name('attendance.search');

        Route::get('/attendance/statistics', [AttendanceController::class, 'statistics'])
            ->name('attendance.statistics');

        Route::post('/attendance', [AttendanceController::class, 'store'])
            ->name('attendance.store');

        Route::patch('/attendance/{attendance}', [AttendanceController::class, 'update'])
            ->name('attendance.update');

        Route::post('/attendance/import', [AttendanceController::class, 'importBiometric'])
            ->name('attendance.import');
    });

    /*
    |----------------------------------------------------------------------
    | Productions & transfers — Admin and Production Manager.
    |----------------------------------------------------------------------
    */
    Route::middleware('role:Admin,Production Manager')->group(function () {
        // Reference lists for the client's dropdowns: the canonical names the
        // filters and writes below accept, including entries holding no stock.
        Route::get('/departments', [ReferenceDataController::class, 'departments'])
            ->name('departments.index');

        Route::get('/workshops', [ReferenceDataController::class, 'workshops'])
            ->name('workshops.index');

        Route::get('/productions', [ProductionController::class, 'index'])->name('productions.index');

        // Must precede /productions/{production} or model binding swallows it.
        Route::get('/productions/workshops', [ProductionController::class, 'workshops'])
            ->name('productions.workshops');

        Route::get('/productions/statistics', [ProductionController::class, 'statistics'])
            ->name('productions.statistics');

        // Also before /productions/{production}: a POST to a literal segment
        // would otherwise be read as a model key.
        Route::post('/productions/import', [ProductionController::class, 'import'])
            ->name('productions.import');

        Route::get('/productions/{production}', [ProductionController::class, 'show'])->name('productions.show');
        Route::post('/productions', [ProductionController::class, 'store'])->name('productions.store');
        Route::patch('/productions/{production}', [ProductionController::class, 'update'])->name('productions.update');

        Route::get('/productions/{production}/activity', [ProductionController::class, 'activity'])
            ->name('productions.activity');

        Route::get('/productions/{production}/transfers', [ProductionTransferController::class, 'history'])
            ->name('productions.transfers');

        Route::get('/transfers', [ProductionTransferController::class, 'index'])->name('transfers.index');

        // Sensitive: the controller re-authenticates the caller before moving stock.
        Route::post('/transfers', [ProductionTransferController::class, 'store'])
            ->name('transfers.store');
    });

   /*
|--------------------------------------------------------------------------
| Audit trail - Admin only.
|--------------------------------------------------------------------------
*/
Route::get('/audit-logs', [AuditLogController::class, 'index'])
    ->middleware('role:Admin')
    ->name('audit-logs.index');

}); // إغلاق مجموعة auth:sanctum

/*
|--------------------------------------------------------------------------
| Temporary Migration Route (Public)
|--------------------------------------------------------------------------
*/
Route::get('/run-migrations', function () {
    try {
        \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
        return 'Migrations completed successfully!<br><pre>' . \Illuminate\Support\Facades\Artisan::output() . '</pre>';
    } catch (\Exception $e) {
        return 'Migration Error: ' . $e->getMessage();
    }
});