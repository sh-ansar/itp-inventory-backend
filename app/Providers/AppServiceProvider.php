<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use App\Http\Middleware\IsolatedXsrfCookie;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Sanctum stateful API uses ValidateCsrfToken directly, not the web
        // middleware replacement. Bind it to Inventory's isolated cookie middleware
        // so /inventory-api never emits the shared XSRF-TOKEN used by other apps.
        $this->app->bind(ValidateCsrfToken::class, IsolatedXsrfCookie::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}