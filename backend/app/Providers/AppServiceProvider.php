<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
    }
}
