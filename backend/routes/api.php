<?php

use App\Http\Controllers\Api\Admin\AdminAiUsageController;
use App\Http\Controllers\Api\Admin\AdminAuditController;
use App\Http\Controllers\Api\Admin\AdminAuditScheduleController;
use App\Http\Controllers\Api\Admin\AdminCmsController;
use App\Http\Controllers\Api\Admin\AdminCrawlJobController;
use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\AdminFailedJobController;
use App\Http\Controllers\Api\Admin\AdminLeadController;
use App\Http\Controllers\Api\Admin\AdminPlanController;
use App\Http\Controllers\Api\Admin\AdminUsageController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\AdminWebsiteController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuditScheduleController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CmsController;
use App\Http\Controllers\Api\LeadController;
use Illuminate\Support\Facades\Route;

// throttle:5,1 (5/min/IP) — previously unthrottled, a real brute-force/
// signup-spam gap closed in Phase 9's security pass.
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Scheduled (recurring) audits — a registered-user, plan-gated feature
    // (AuditSchedulerService::canCreateSchedule), so unlike audit creation
    // below there is no anonymous path here.
    Route::get('/audit-schedules', [AuditScheduleController::class, 'index']);
    Route::post('/audit-schedules', [AuditScheduleController::class, 'store']);
    Route::put('/audit-schedules/{auditSchedule}', [AuditScheduleController::class, 'update']);
    Route::delete('/audit-schedules/{auditSchedule}', [AuditScheduleController::class, 'destroy']);
});

// Anonymous-friendly: auth is optional here, resolved manually via
// $request->user('sanctum') inside the controller so both anonymous and
// registered callers can create/view audits with different limits/
// visibility, without the auth:sanctum middleware rejecting the
// unauthenticated case outright.
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/audits', [AuditController::class, 'store']);
    Route::get('/audits/{audit}', [AuditController::class, 'show']);
    Route::get('/audits/{audit}/report', [AuditController::class, 'report']);
    Route::get('/audits/{audit}/report/pdf', [AuditController::class, 'reportPdf']);
});

// Public — lead capture CTAs and CMS content for the (future) frontend.
// Leads are throttled like audit creation (Phase 9 — LeadController's
// old comment claimed a "shared api throttle" that didn't actually
// exist; this route was unthrottled until now). CMS reads are cheap,
// public, and read-only, so left unthrottled like any static content.
Route::post('/leads', [LeadController::class, 'store'])->middleware('throttle:10,1');
Route::get('/cms/{slug}', [CmsController::class, 'show']);

Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/dashboard', [AdminDashboardController::class, 'index']);

    Route::get('/users', [AdminUserController::class, 'index']);
    Route::get('/websites', [AdminWebsiteController::class, 'index']);

    Route::get('/audits', [AdminAuditController::class, 'index']);
    Route::get('/audits/{audit}', [AdminAuditController::class, 'show']);

    Route::get('/crawl-jobs', [AdminCrawlJobController::class, 'index']);
    Route::get('/audit-schedules', [AdminAuditScheduleController::class, 'index']);

    Route::get('/failed-jobs', [AdminFailedJobController::class, 'index']);
    Route::delete('/failed-jobs/{id}', [AdminFailedJobController::class, 'destroy']);

    Route::get('/ai-usage', [AdminAiUsageController::class, 'index']);
    Route::get('/usage', [AdminUsageController::class, 'index']);

    Route::apiResource('plans', AdminPlanController::class)->except(['show']);

    Route::get('/leads', [AdminLeadController::class, 'index']);
    Route::get('/leads/{lead}', [AdminLeadController::class, 'show']);
    Route::put('/leads/{lead}', [AdminLeadController::class, 'update']);
    Route::post('/leads/{lead}/activities', [AdminLeadController::class, 'storeActivity']);

    Route::get('/cms', [AdminCmsController::class, 'index']);
    Route::post('/cms', [AdminCmsController::class, 'store']);
    Route::get('/cms/{page}', [AdminCmsController::class, 'show']);
    Route::put('/cms/{page}', [AdminCmsController::class, 'update']);
    Route::delete('/cms/{page}', [AdminCmsController::class, 'destroy']);
    Route::put('/cms/{page}/sections/{sectionKey}', [AdminCmsController::class, 'putSection']);
    Route::delete('/cms/{page}/sections/{sectionKey}', [AdminCmsController::class, 'destroySection']);
});
