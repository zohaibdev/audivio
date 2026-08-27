<?php

namespace App\Providers;

use Illuminate\Database\Schema\Builder;
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
    }
}
