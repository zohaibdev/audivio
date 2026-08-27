<?php

use App\Http\Controllers\Api\Admin\AdminAiUsageController;
use App\Http\Controllers\Api\Admin\AdminApiUsageController;
use App\Http\Controllers\Api\Admin\AdminAuditController;
use App\Http\Controllers\Api\Admin\AdminAuditScheduleController;
use App\Http\Controllers\Api\Admin\AdminCmsController;
use App\Http\Controllers\Api\Admin\AdminCrawlJobController;
use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\AdminFailedJobController;
use App\Http\Controllers\Api\Admin\AdminLeadController;
use App\Http\Controllers\Api\Admin\AdminPlanController;
use App\Http\Controllers\Api\Admin\AdminProviderController;
use App\Http\Controllers\Api\Admin\AdminSystemHealthController;
use App\Http\Controllers\Api\Admin\AdminUsageController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\AdminWebsiteController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuditScheduleController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CmsController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\WebsiteController;
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

    // Self-service "my websites"/"my audits" — distinct from the
    // Api\Admin equivalents, which list every user's data. Anonymous
    // visitors have no persistent identity to list against, hence
    // auth:sanctum rather than the optional-auth pattern audits/report
    // use below.
    Route::get('/websites', [WebsiteController::class, 'index']);
    Route::get('/audits', [AuditController::class, 'index']);
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
Route::get('/plans', [PlanController::class, 'index']);

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

    Route::get('/system-health', [AdminSystemHealthController::class, 'index']);
    Route::get('/ai-usage', [AdminAiUsageController::class, 'index']);
    Route::get('/usage', [AdminUsageController::class, 'index']);

    // AI & API provider configuration — credentials are encrypted at rest
    // (App\Models\ApiProvider) and never returned in full; every response
    // here carries only masked_key. {apiProvider} binds by slug (claude,
    // openai, gemini, pagespeed), not numeric id.
    Route::get('/providers', [AdminProviderController::class, 'index']);
    Route::get('/providers/{apiProvider}', [AdminProviderController::class, 'show']);
    Route::put('/providers/{apiProvider}', [AdminProviderController::class, 'update']);
    Route::patch('/providers/{apiProvider}/status', [AdminProviderController::class, 'updateStatus']);
    Route::post('/providers/{apiProvider}/test', [AdminProviderController::class, 'test']);

    // Cross-provider request/cost telemetry from api_usage_logs — distinct
    // from /ai-usage above, which reports on ai_analyses (per-audit AI
    // results, not raw request-level ops data across every provider).
    Route::get('/usage/summary', [AdminApiUsageController::class, 'summary']);
    Route::get('/usage/daily', [AdminApiUsageController::class, 'daily']);
    Route::get('/usage/by-provider', [AdminApiUsageController::class, 'byProvider']);
    Route::get('/usage/logs', [AdminApiUsageController::class, 'logs']);

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
