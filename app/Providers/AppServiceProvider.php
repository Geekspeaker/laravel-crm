<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
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
        // Behind Northflank/Fastly TLS termination the app receives plain HTTP,
        // so force HTTPS URL generation in production to avoid mixed-content.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
