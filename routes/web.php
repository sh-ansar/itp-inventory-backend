<?php

use App\Http\Controllers\InventorySessionController;
use App\Http\Controllers\Api\Commercial\RegistrationController;
use Illuminate\Support\Facades\Route;

Route::middleware('inventory.browser-login')->group(function (): void {
    Route::post('/auth/login', [InventorySessionController::class, 'login'])->middleware('throttle:5,1,inventory-login');
    Route::post('/auth/register', [RegistrationController::class, 'register'])->middleware('throttle:3,1,inventory-register');
    Route::post('/auth/verify-email', [RegistrationController::class, 'verify'])->middleware('throttle:10,1,inventory-verify');
    Route::post('/auth/resend-verification', [RegistrationController::class, 'resend'])->middleware('throttle:3,1,inventory-resend');
    Route::middleware('auth:web')->group(function (): void {
        Route::get('/auth/me', [InventorySessionController::class, 'me']);
        Route::post('/auth/logout', [InventorySessionController::class, 'logout']);
    });
});
