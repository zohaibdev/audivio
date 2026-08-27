<?php

use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
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
});
