<?php

use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\AssetReferenceController;
use App\Http\Controllers\Api\AssetMovementController;
use App\Http\Controllers\Api\AssetQrController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'service' => 'itp-inventory-api',
        'browser_login' => (bool) config('inventory.browser_login_enabled'),
    ]));
    Route::middleware(['auth:sanctum', 'inventory.active-account'])->group(function (): void {
        Route::get('/me', fn () => response()->json([
            'id' => auth()->id(), 'name' => auth()->user()->name,
        ]));
        Route::get('/companies', fn () => response()->json(
            auth()->user()->companyMemberships()->with('company')->get()->map(fn ($membership) => [
                'id' => $membership->company_id,
                'name' => $membership->company->name,
                'role_code' => $membership->role_code,
            ])
        ));

        Route::prefix('companies/{company}')->group(function (): void {
            Route::get('/reference-data', [AssetReferenceController::class, 'index']);
            Route::post('/categories', [AssetReferenceController::class, 'storeCategory']);
            Route::post('/locations', [AssetReferenceController::class, 'storeLocation']);
            Route::get('/assets', [AssetController::class, 'index']);
            Route::get('/assets/scan/{publicId}', [AssetQrController::class, 'show']);
            Route::post('/assets', [AssetController::class, 'store']);
            Route::get('/assets/{asset}', [AssetController::class, 'show']);
            Route::put('/assets/{asset}', [AssetController::class, 'update']);
            Route::patch('/assets/{asset}', [AssetController::class, 'update']);
            Route::delete('/assets/{asset}', [AssetController::class, 'destroy']);
            Route::get('/assets/{asset}/events', [AssetController::class, 'events']);
            Route::get('/assets/{asset}/movements', [AssetMovementController::class, 'index']);
            Route::post('/assets/{asset}/movements', [AssetMovementController::class, 'store']);
        });
    });
});