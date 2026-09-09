<?php

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Services\Schedule\AuditSchedulerService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['admin' => EnsureUserIsAdmin::class]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        // Hourly, not per-minute: recurring audits are day/week/month
        // granularity, so this only needs to be frequent enough that a
        // due schedule doesn't sit unrun for long, not exact-to-the-minute.
        $schedule->call(fn () => app(AuditSchedulerService::class)->runDue())
            ->hourly()
            ->name('audit-schedules:run-due')
            ->withoutOverlapping();

        // Every five minutes, not hourly: a stuck audit holds one of its
        // owner's concurrent slots, so until this runs the user simply
        // cannot start another audit. The command is a single indexed
        // query when nothing is stuck, which is the overwhelmingly common
        // case, so running it often is cheap.
        $schedule->command('audits:recover-stuck')
            ->everyFiveMinutes()
            ->name('audits:recover-stuck')
            ->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
