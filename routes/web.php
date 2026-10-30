<?php

use App\Http\Controllers\InventorySessionController;
use Illuminate\Support\Facades\Route;

// No public registration. Browser login is feature-gated until HTTPS QA.
Route::middleware('inventory.browser-login')->group(function (): void {
    Route::post('/auth/login', [InventorySessionController::class, 'login'])->middleware('throttle:5,1');
    Route::middleware('auth:web')->group(function (): void {
        Route::get('/auth/me', [InventorySessionController::class, 'me']);
        Route::post('/auth/logout', [InventorySessionController::class, 'logout']);
    });
});