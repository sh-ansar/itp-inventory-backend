<?php

namespace App\Http\Controllers\Api;

use App\Domain\Inventory\AssetAuditWriter;
use App\Domain\Inventory\CompanyAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssetRegistry\MoveAssetRequest;
use App\Http\Resources\AssetMovementResource;
use App\Http\Resources\AssetResource;
use App\Models\Asset;
use App\Models\AssetLocation;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AssetMovementController extends Controller
{
    public function index(
        Request $request, Company $company, string $asset, CompanyAccess $access
    ): AnonymousResourceCollection {
        $access->member($request, $company);
        $model = $company->assets()->withTrashed()->whereKey($asset)->firstOrFail();
        return AssetMovementResource::collection(
            $model->movements()->orderByDesc('version')->orderByDesc('id')->paginate(25)
        );
    }

    public function store(
        MoveAssetRequest $request, Company $company, string $asset,
        CompanyAccess $access, AssetAuditWriter $audit
    ): JsonResponse {
        $access->canWrite($request, $company);
        $data = $request->validated();

        [$model, $movement] = DB::transaction(function () use ($request, $company, $asset, $data, $audit): array {
            // Serialise all changes to one asset across all writers in PostgreSQL.
            $model = $company->assets()->whereKey($asset)->lockForUpdate()->firstOrFail();
            abort_unless($model->version === $data['version'], 409, 'Asset was modified; reload before moving');
            abort_unless($model->status === 'active', 409, 'Only active assets may be moved');

            $fromId = $model->physical_location_id;
            $toId = $data['to_location_id'];
            if ($fromId === $toId) {
                throw ValidationException::withMessages([
                    'to_location_id' => ['Select a different physical location'],
                ]);
            }

            // Re-query within the transaction: protect against a simultaneous
            // deletion of a referenced location and snapshot current names.
            $to = $company->assetLocations()->where('kind', 'physical')
                ->whereKey($toId)->firstOrFail();
            $from = $fromId
                ? $company->assetLocations()->whereKey($fromId)->firstOrFail()
                : null;

            $before = [
                'physical_location_id' => $fromId,
                'physical_location_name' => $from?->name,
                'version' => $model->version,
            ];

            $model->physical_location_id = $to->id;
            $model->version++;
            $model->save();

            $movement = $model->movements()->create([
                'id' => (string) Str::uuid(),
                'company_id' => $company->id,
                'from_location_id' => $from?->id,
                'to_location_id' => $to->id,
                'from_location_name' => $from?->name,
                'to_location_name' => $to->name,
                'actor_id' => $request->user()->getAuthIdentifier(),
                'actor_name' => $request->user()->name,
                'reason' => $data['reason'],
                'version' => $model->version,
            ]);
            $audit->record($model, $request->user(), 'moved', $before, [
                'physical_location_id' => $to->id,
                'physical_location_name' => $to->name,
                'version' => $model->version,
            ], $data['reason']);

            return [$model, $movement];
        });

        return response()->json([
            'data' => [
                'asset' => (new AssetResource($model->load([
                    'category','accountingLocation','physicalLocation',
                ])))->resolve($request),
                'movement' => (new AssetMovementResource($movement))->resolve($request),
            ],
        ], 201);
    }
}