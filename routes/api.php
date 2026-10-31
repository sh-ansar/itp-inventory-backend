<?php

use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\AssetMovementController;
use App\Http\Controllers\Api\AssetQrController;
use App\Http\Controllers\Api\AssetReferenceController;
use App\Http\Controllers\Api\EquipmentTypeController;
use App\Http\Controllers\Api\FacilityNodeController;
use App\Http\Controllers\Api\FacilityPlanController;
use App\Http\Controllers\Api\InventorySessionController;
use App\Http\Controllers\Api\ResponsiblePersonController;
use App\Http\Controllers\Api\WarehouseController;
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
            Route::get('/inventory-sessions', [InventorySessionController::class, 'index']);
            Route::post('/inventory-sessions', [InventorySessionController::class, 'store']);
            Route::get('/inventory-sessions/{session}', [InventorySessionController::class, 'show']);
            Route::get('/inventory-sessions/{session}/items', [InventorySessionController::class, 'items']);
            Route::put('/inventory-sessions/{session}/items/{item}', [InventorySessionController::class, 'count']);
            Route::post('/inventory-sessions/{session}/scan', [InventorySessionController::class, 'scan']);
            Route::post('/inventory-sessions/{session}/complete', [InventorySessionController::class, 'complete']);
            Route::post('/inventory-sessions/{session}/reopen', [InventorySessionController::class, 'reopen']);
            Route::get('/inventory-sessions/{session}/events', [InventorySessionController::class, 'events']);
            Route::get('/inventory-sessions/{session}/report.csv', [InventorySessionController::class, 'export']);
            Route::get('/stock-items', [WarehouseController::class, 'items']);
            Route::post('/stock-items', [WarehouseController::class, 'storeItem']);
            Route::get('/stock-balances', [WarehouseController::class, 'balances']);
            Route::get('/stock-transactions', [WarehouseController::class, 'transactions']);
            Route::post('/stock-transactions', [WarehouseController::class, 'postTransaction']);
            Route::get('/facility-nodes', [FacilityNodeController::class, 'index']);
            Route::post('/facility-nodes', [FacilityNodeController::class, 'store']);
            Route::get('/facility-nodes/{node}', [FacilityNodeController::class, 'show']);
            Route::put('/facility-nodes/{node}', [FacilityNodeController::class, 'update']);
            Route::get('/facility-nodes/{node}/events', [FacilityNodeController::class, 'events']);
            Route::get('/facility-nodes/{node}/plan', [FacilityPlanController::class, 'show']);
            Route::put('/facility-nodes/{node}/plan', [FacilityPlanController::class, 'resize']);
            Route::get('/facility-nodes/{node}/plan/events', [FacilityPlanController::class, 'events']);
            Route::get('/facility-nodes/{node}/plan/background', [FacilityPlanController::class, 'background']);
            Route::post('/facility-nodes/{node}/plan/background', [FacilityPlanController::class, 'uploadBackground']);
            Route::delete('/facility-nodes/{node}/plan/background', [FacilityPlanController::class, 'clearBackground']);
            Route::post('/facility-nodes/{node}/plan/placements', [FacilityPlanController::class, 'place']);
            Route::patch('/facility-nodes/{node}/plan/placements/{placement}', [FacilityPlanController::class, 'move']);
            Route::delete('/facility-nodes/{node}/plan/placements/{placement}', [FacilityPlanController::class, 'remove']);
            Route::get('/responsible-people', [ResponsiblePersonController::class, 'index']);
            Route::post('/responsible-people', [ResponsiblePersonController::class, 'store']);
            Route::put('/responsible-people/{person}', [ResponsiblePersonController::class, 'update']);
            Route::get('/reference-data', [AssetReferenceController::class, 'index']);
            Route::post('/categories', [AssetReferenceController::class, 'storeCategory']);
            Route::get('/equipment-presets', [EquipmentTypeController::class, 'presets']);
            Route::post('/equipment-presets/initialize', [EquipmentTypeController::class, 'initialize']);
            Route::put('/categories/{category}', [EquipmentTypeController::class, 'update']);
            Route::post('/categories/{category}/icon', [EquipmentTypeController::class, 'uploadIcon']);
            Route::get('/categories/{category}/icon', [EquipmentTypeController::class, 'icon']);
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
