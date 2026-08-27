<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Schema\Builder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // MySQL 5.7 without innodb_large_prefix caps InnoDB index keys at
        // 767 bytes; a utf8mb4 varchar(255) unique index exceeds that.
        Builder::defaultStringLength(191);

        RateLimiter::for('crawler', function () {
            $perMinute = (int) round(config('crawler.rate_limit_per_second', 2) * 60);

            return Limit::perMinute(max(1, $perMinute));
        });

        // Applies wherever `Password::defaults()` is used (currently just
        // RegisterRequest) — without this, Laravel's own default is only
        // "at least 8 characters," with no complexity requirement at all.
        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        // Baseline security headers on every response, including ones
        // rendered from an exception (401/403/422/429/404 etc). A
        // terminable middleware would miss those: middleware "after"
        // code only runs on the success path — an exception thrown
        // deeper in the pipeline (e.g. AuthenticationException from
        // auth:sanctum) unwinds straight to the Kernel's exception
        // handler, skipping every middleware's post-$next() code.
        // RequestHandled fires after the response is finalized either
        // way, so this is the one hook that actually covers both.
        Event::listen(RequestHandled::class, function (RequestHandled $event): void {
            $event->response->headers->set('X-Content-Type-Options', 'nosniff');
            $event->response->headers->set('X-Frame-Options', 'DENY');
            $event->response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        });
    }
}
