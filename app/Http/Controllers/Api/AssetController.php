<?php

namespace App\Http\Controllers\Api;

use App\Domain\Inventory\AssetAuditWriter;
use App\Domain\Inventory\CompanyAccess;
use App\Domain\Inventory\FacilityHierarchy;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssetRegistry\ArchiveAssetRequest;
use App\Http\Requests\AssetRegistry\StoreAssetRequest;
use App\Http\Requests\AssetRegistry\UpdateAssetRequest;
use App\Http\Resources\AssetEventResource;
use App\Http\Resources\AssetResource;
use App\Models\Asset;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class AssetController extends Controller
{
    private const EDITABLE = [
        'inventory_number', 'name', 'serial_number', 'description',
        'category_id', 'accounting_location_id', 'physical_location_id',
        'facility_node_id', 'responsible_person_id',
    ];

    private function scope(Company $company, string $id, bool $withTrashed = false): Asset
    {
        $query = $company->assets()->with(['category', 'accountingLocation', 'physicalLocation', 'facilityNode', 'responsiblePerson']);
        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query->whereKey($id)->firstOrFail();
    }

    private function snapshot(Asset $asset): array
    {
        return Arr::only($asset->getAttributes(), [...self::EDITABLE, 'status', 'version']);
    }

    public function index(Request $request, Company $company, CompanyAccess $access): AnonymousResourceCollection
    {
        $access->member($request, $company);
        $params = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'facility_node_id' => ['nullable', 'uuid', Rule::exists('facility_nodes', 'id')->where('company_id', $company->id)],
            'include_descendants' => ['nullable', 'boolean'],
            'responsible_person_id' => ['nullable', 'uuid', Rule::exists('responsible_people', 'id')->where('company_id', $company->id)],
            'status' => ['nullable', Rule::in(['active', 'written_off', 'disposed'])],
            'sort' => ['nullable', Rule::in(['inventory_number', 'name', 'created_at', 'updated_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = $company->assets()->with(['category', 'accountingLocation', 'physicalLocation', 'facilityNode', 'responsiblePerson']);
        if (! empty($params['search'])) {
            $term = mb_strtolower(trim($params['search']));
            // '!' is a portable SQL LIKE escape for PostgreSQL and SQLite.
            $term = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
            $pattern = '%'.$term.'%';
            $query->where(fn ($q) => $q
                ->whereRaw("LOWER(inventory_number) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(serial_number) LIKE ? ESCAPE '!'", [$pattern]));
        }
        if (! empty($params['facility_node_id'])) {
            $ids = ($params['include_descendants'] ?? true)
                ? app(FacilityHierarchy::class)
                    ->descendants($company, $params['facility_node_id'])
                : [$params['facility_node_id']];
            $query->whereIn('facility_node_id', $ids);
        }
        if (! empty($params['responsible_person_id'])) {
            $query->where('responsible_person_id', $params['responsible_person_id']);
        }
        if (! empty($params['status'])) {
            $query->where('status', $params['status']);
        }
        $sort = $params['sort'] ?? 'inventory_number';
        $direction = $params['direction'] ?? 'asc';

        return AssetResource::collection(
            $query->orderBy($sort, $direction)->orderBy('id')
                ->paginate($params['per_page'] ?? 25)
                ->appends($request->query())
        );
    }

    public function show(Request $request, Company $company, string $asset, CompanyAccess $access): AssetResource
    {
        $access->member($request, $company);

        return new AssetResource($this->scope($company, $asset));
    }

    public function store(
        StoreAssetRequest $request, Company $company,
        CompanyAccess $access, AssetAuditWriter $audit
    ): JsonResponse {
        $access->canWrite($request, $company);
        $values = Arr::only($request->validated(), self::EDITABLE);
        $model = DB::transaction(function () use ($request, $company, $values, $audit): Asset {
            $asset = $company->assets()->create([
                ...$values,
                'id' => (string) Str::uuid(),
                'public_id' => (string) Str::uuid(),
                'status' => 'active',
            ]);
            $asset->refresh(); // Database default version=1 is not populated on the in-memory model.
            $audit->record($asset, $request->user(), 'created', null, $this->snapshot($asset));

            return $asset;
        });

        return (new AssetResource($model->load(['category', 'accountingLocation', 'physicalLocation', 'facilityNode', 'responsiblePerson'])))
            ->response()->setStatusCode(201);
    }

    public function update(
        UpdateAssetRequest $request, Company $company, string $asset,
        CompanyAccess $access, AssetAuditWriter $audit
    ): AssetResource {
        $access->canWrite($request, $company);
        $data = $request->validated();
        $model = DB::transaction(function () use ($request, $company, $asset, $data, $audit): Asset {
            $model = $company->assets()->whereKey($asset)->lockForUpdate()->firstOrFail();
            abort_unless($model->version === $data['version'], 409, 'Asset was modified; reload before saving');
            $before = $this->snapshot($model);
            $model->fill(Arr::only($data, self::EDITABLE));
            if ($model->isDirty()) {
                $model->version++;
                $model->save();
                $audit->record($model, $request->user(), 'updated', $before, $this->snapshot($model));
            }

            return $model;
        });

        return new AssetResource($model->load(['category', 'accountingLocation', 'physicalLocation', 'facilityNode', 'responsiblePerson']));
    }

    public function destroy(
        ArchiveAssetRequest $request, Company $company, string $asset,
        CompanyAccess $access, AssetAuditWriter $audit
    ): JsonResponse {
        $access->canArchive($request, $company);
        DB::transaction(function () use ($request, $company, $asset, $audit): void {
            $model = $company->assets()->whereKey($asset)->lockForUpdate()->firstOrFail();
            $data = $request->validated();
            abort_unless($model->version === $data['version'], 409, 'Asset was modified; reload before archiving');
            $before = $this->snapshot($model);
            $model->version++;
            $model->save();
            $audit->record($model, $request->user(), 'archived', $before, [
                ...$this->snapshot($model), 'archived' => true,
            ], trim($data['reason']));
            $model->delete(); // Keep audit/history and inventory number reserved.
        });

        return response()->json(['ok' => true]);
    }

    public function events(
        Request $request, Company $company, string $asset, CompanyAccess $access
    ): AnonymousResourceCollection {
        $access->member($request, $company);
        $model = $this->scope($company, $asset, true);

        return AssetEventResource::collection(
            $model->events()->orderByDesc('version')->orderByDesc('id')->paginate(25)
        );
    }
}
