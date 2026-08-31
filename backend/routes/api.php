<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\WebAuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DevicePairingController;
use App\Http\Controllers\Api\FinalOutputController;
use App\Http\Controllers\Api\HhtImportController;
use App\Http\Controllers\Api\HhtSubmissionController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\ItemStockController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\ShopController;
use App\Http\Controllers\Api\StockAdjustmentController;
use App\Http\Controllers\Api\StockImportController;
use App\Http\Controllers\Api\StockTakeController;
use App\Http\Controllers\Api\StockTakeSessionController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VarianceController;
use App\Http\Controllers\Api\VerificationController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| PharmaVerify API
|------------------------------------------------------------------------------
| Every route below the authentication group requires a Sanctum token. Access
| within a route is decided by permission, checked inside the controller, so a
| user who reaches an endpoint they are not entitled to receives 403 regardless
| of what the frontend allowed them to click.
*/

Route::post('auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login')
    ->name('auth.login');

// A handheld pairing for the first time has nothing to authenticate with, so
// this one endpoint is public. It is throttled hard and answers every failure
// identically, so it cannot be used to discover device codes.
Route::post('hht/devices/pair', [DevicePairingController::class, 'pair'])
    ->middleware('throttle:6,1');

// Public aggregate counts shown on the login page - no sensitive data exposed.
Route::get('public/stats', [DashboardController::class, 'publicStats']);

Route::middleware('auth:sanctum')->group(function () {
    // ---------------------------------------------------------------- Auth
    Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

    // ----------------------------------------------------------- Dashboard
    Route::get('dashboard/summary', [DashboardController::class, 'summary']);

    // -------------------------------------------------------------- Master
    Route::get('shops/options', [ShopController::class, 'options']);
    Route::apiResource('shops', ShopController::class);

    Route::apiResource('items', ItemController::class);

    Route::get('devices/options', [DeviceController::class, 'options']);
    Route::apiResource('devices', DeviceController::class)->except(['show']);

    // ------------------------------------------------------- Stock import
    Route::post('stock-imports/preview', [StockImportController::class, 'preview'])
        ->middleware('throttle:stock-import');
    Route::get('stock-imports/template', [StockImportController::class, 'template']);
    Route::get('stock-imports/{stockImport}/errors', [StockImportController::class, 'errors']);
    Route::apiResource('stock-imports', StockImportController::class)
        ->only(['index', 'store', 'show'])
        ->middlewareFor('store', 'throttle:stock-import');

    // --------------------------------------------------------- Item stock
    // ------------------------------------------------------- Item master
    // Item Import maintains the product list; Stock Import loads quantities.
    // They are separate operations on purpose.
    Route::post('items/import', [ItemController::class, 'import'])
        ->middleware('throttle:stock-import');

    // Declared before the resource so `lookup` is not read as an {itemStock}.
    Route::get('item-stocks/lookup', [ItemStockController::class, 'lookup']);
    Route::apiResource('item-stocks', ItemStockController::class)->only(['index', 'show']);

    // -------------------------------------------------- HHT submissions
    // Reachable by a paired handheld's own token: a cheap way to prove the
    // server is up and the token still good without sending a count to find out.
    Route::get('hht/devices/me', [DevicePairingController::class, 'me']);

    Route::post('devices/{device}/pairing-code', [DevicePairingController::class, 'issue']);
    Route::delete('devices/{device}/pairing', [DevicePairingController::class, 'revoke']);

    // ------------------------------------------------------ HHT imports
    // The handheld has no network path, so a completed count arrives as a
    // workbook. The kind is decided from the file's own headings.
    Route::post('hht/imports/preview', [HhtImportController::class, 'preview'])
        ->middleware('throttle:stock-import');
    Route::post('hht/imports', [HhtImportController::class, 'store'])
        ->middleware('throttle:stock-import');

    Route::post('hht/submissions', [HhtSubmissionController::class, 'store']);
    Route::get('hht/submissions', [HhtSubmissionController::class, 'index']);
    Route::get('hht/submissions/{hhtSubmission}', [HhtSubmissionController::class, 'show']);

    // -------------------------------------------------------- Stock audit
    // Counting from the browser. Registered before the {audit} routes below
    // so 'audits/start' is matched as a literal path and never mistaken for an
    // audit whose id happens to read 'start'.
    Route::post('audits/start', [WebAuditController::class, 'start']);
    Route::get('audits/lookup', [WebAuditController::class, 'lookup']);
    Route::post('audits/{audit}/count', [WebAuditController::class, 'count']);
    Route::delete('audits/{audit}/count/{line}', [WebAuditController::class, 'removeCount']);
    Route::post('audits/{audit}/complete', [WebAuditController::class, 'complete']);

    Route::get('audits', [AuditController::class, 'index']);
    Route::get('audits/{audit}', [AuditController::class, 'show']);
    Route::get('audits/{audit}/lines', [AuditController::class, 'lines']);
    Route::post('audits/{audit}/verify', [AuditController::class, 'verify']);

    // ------------------------------------------------------- Verification
    Route::get('verification', [VerificationController::class, 'index']);
    Route::patch('verification/lines/{auditLine}', [VerificationController::class, 'update']);

    // ------------------------------------------------------------ Variance
    Route::get('variance', [VarianceController::class, 'index']);
    Route::get('variance/summary', [VarianceController::class, 'summaryResponse']);

    // ---------------------------------------------------------- Adjustment
    Route::apiResource('adjustments', StockAdjustmentController::class)->only(['index', 'store', 'show']);

    // ---------------------------------------------------------- Stock take
    // A cycle groups the lines of one sweep under a reference the operator can
    // quote - STK-ddMMyyyy-NNNN, numbered per shop and never reused.
    Route::post('stock-take-sessions/{stockTakeSession}/complete', [StockTakeSessionController::class, 'complete']);
    Route::apiResource('stock-take-sessions', StockTakeSessionController::class)->only(['index', 'store', 'show']);

    Route::get('stock-takes/candidates', [StockTakeController::class, 'candidates']);
    Route::apiResource('stock-takes', StockTakeController::class)->except(['show']);

    // ------------------------------------------------------------- Reports
    Route::get('reports', [ReportController::class, 'index']);
    Route::get('reports/{report}', [ReportController::class, 'show']);

    // -------------------------------------------------------- Final output
    Route::post('final-outputs/{finalOutput}/share-onedrive', [FinalOutputController::class, 'shareToOneDrive']);
    Route::get('final-outputs/{finalOutput}/download', [FinalOutputController::class, 'download']);
    Route::apiResource('final-outputs', FinalOutputController::class)->only(['index', 'store', 'show']);

    // ----------------------------------------------------- Administration
    Route::get('users/roles', [UserController::class, 'roles']);
    Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword']);
    Route::post('users/{user}/toggle-status', [UserController::class, 'toggleStatus']);
    Route::apiResource('users', UserController::class)->except(['destroy']);

    Route::get('settings', [SettingController::class, 'index']);
    Route::put('settings', [SettingController::class, 'update']);

    Route::get('activity-log', [ActivityLogController::class, 'index']);
});
