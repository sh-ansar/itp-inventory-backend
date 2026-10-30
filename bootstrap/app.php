<?php

use App\Http\Middleware\ApiErrorCode;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureBrowserLoginEnabled;
use App\Http\Middleware\IsolatedXsrfCookie;
use App\Http\Middleware\RequestCorrelationId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Same-origin SPA; never share cookies or XSRF token names with HRM/Portal.
        $middleware->statefulApi();
        $middleware->web(replace: [ValidateCsrfToken::class => IsolatedXsrfCookie::class]);
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*','auth/*') ? null : '/auth/login'
        );
        $middleware->api(prepend: [RequestCorrelationId::class, ApiErrorCode::class]);
        $middleware->alias([
            'inventory.browser-login' => EnsureBrowserLoginEnabled::class,
            'inventory.active-account' => EnsureActiveAccount::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*','auth/*') || $request->expectsJson()
        );
    })->create();