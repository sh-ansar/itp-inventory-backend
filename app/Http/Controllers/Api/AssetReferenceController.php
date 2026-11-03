<?php

namespace App\Http\Controllers\Api;

use App\Domain\Inventory\CompanyAccess;
use App\Domain\Inventory\EquipmentTypes;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AssetReferenceController extends Controller
{
    public function index(Request $request, Company $company, CompanyAccess $access): JsonResponse
    {
        $access->member($request, $company);
        $includeArchived = $request->boolean('include_archived');
        $activeOnly = fn ($query) => $includeArchived ? $query : $query->where('is_active', true);

        return response()->json([
            'categories' => $activeOnly($company->assetCategories())->orderBy('name')->get()->map(fn ($category) => [
                ...$category->only(['id', 'code', 'name', 'icon_key', 'is_base', 'is_active', 'version']),
                'icon_url' => $category->icon_path ? '/inventory-api/api/v1/companies/'.$company->id
                    .'/categories/'.$category->id.'/icon' : null,
            ]),
            'accounting_locations' => $activeOnly($company->assetLocations()->where('kind', 'accounting'))->orderBy('name')->get(['id', 'code', 'name', 'is_active', 'version']),
            'physical_locations' => $activeOnly($company->assetLocations()->where('kind', 'physical'))->orderBy('name')->get(['id', 'code', 'name', 'is_active', 'version']),
        ]);
    }

    public function storeCategory(Request $request, Company $company, CompanyAccess $access): JsonResponse
    {
        $access->canWrite($request, $company);
        $data = $request->validate([
            'code' => ['required', 'string', 'min:1', 'max:60',
                Rule::unique('asset_categories', 'code')->where('company_id', $company->id)],
            'name' => ['required', 'string', 'min:2', 'max:200'],
            'icon_key' => ['nullable', Rule::in(EquipmentTypes::ICONS)],
        ]);
        $normalized = EquipmentTypes::normalize($data['name']);
        if ($company->assetCategories()->where('normalized_name', $normalized)
            ->orWhere(fn ($q) => $q->where('company_id', $company->id)
                ->whereRaw('LOWER(name) = ?', [$normalized]))->exists()) {
            throw ValidationException::withMessages([
                'name' => ['This equipment type already exists in the company'],
            ]);
        }
        $category = $company->assetCategories()->create([
            ...$data, 'id' => (string) Str::uuid(),
            'normalized_name' => $normalized, 'icon_key' => $data['icon_key'] ?? 'box',
            'is_base' => false, 'is_active' => true, 'version' => 1,
        ]);

        return response()->json($category->only(['id', 'code', 'name', 'icon_key', 'is_base', 'version']), 201);
    }

    public function storeLocation(Request $request, Company $company, CompanyAccess $access): JsonResponse
    {
        $access->canWrite($request, $company);
        $data = $request->validate([
            'kind' => ['required', Rule::in(['accounting', 'physical'])],
            'code' => ['required', 'string', 'min:1', 'max:60'],
            'name' => ['required', 'string', 'min:2', 'max:200'],
        ]);
        $request->validate([
            'code' => [Rule::unique('asset_locations', 'code')
                ->where('company_id', $company->id)->where('kind', $data['kind'])],
        ]);
        $normalized = EquipmentTypes::normalize($data['name']);
        $duplicate = DB::table('asset_locations')->where('company_id', $company->id)
            ->where('kind', $data['kind'])->pluck('name')
            ->contains(fn ($existing) => EquipmentTypes::normalize((string) $existing) === $normalized);
        if ($duplicate) {
            throw ValidationException::withMessages(['name' => ['Location already exists in this company and kind']]);
        }
        $location = $company->assetLocations()->create([
            ...$data, 'id' => (string) Str::uuid(),
        ]);

        return response()->json($location->only(['id', 'kind', 'code', 'name']), 201);
    }
    public function updateLocation(Request $request, Company $company, string $location, CompanyAccess $access): JsonResponse
    {
        $access->canWrite($request, $company);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'min:2', 'max:200'],
        ]);
        $model = DB::transaction(function () use ($company, $location, $data) {
            $row = $company->assetLocations()->whereKey($location)->lockForUpdate()->firstOrFail();
            abort_unless($row->version === $data['version'], 409, 'Reference changed; reload');
            abort_unless($row->is_active, 409, 'Cannot edit archived reference');
            $normalized = EquipmentTypes::normalize($data['name']);
            $duplicate = DB::table('asset_locations')->where('company_id', $company->id)
                ->where('kind', $row->kind)->where('id', '!=', $row->id)->pluck('name')
                ->contains(fn ($other) => EquipmentTypes::normalize((string) $other) === $normalized);
            if ($duplicate) {
                throw ValidationException::withMessages(['name' => ['Location already exists in this company and kind']]);
            }
            $row->name = trim($data['name']);
            if ($row->isDirty()) {
                $row->version++;
                $row->save();
            }
            return $row->refresh();
        }, 3);
        return response()->json(['data' => $model]);
    }

    public function archiveLocation(Request $request, Company $company, string $location, CompanyAccess $access): JsonResponse
    {
        $access->canWrite($request, $company);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $model = DB::transaction(function () use ($company, $location, $data) {
            $row = $company->assetLocations()->whereKey($location)->lockForUpdate()->firstOrFail();
            abort_unless($row->version === $data['version'], 409, 'Reference changed; reload');
            abort_unless($row->is_active, 409, 'Already archived');
            $row->is_active = false;
            $row->version++;
            $row->save();
            return $row->refresh();
        }, 3);
        return response()->json(['data' => $model]);
    }

    public function archiveCategory(Request $request, Company $company, string $category, CompanyAccess $access): JsonResponse
    {
        $access->canWrite($request, $company);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $model = DB::transaction(function () use ($company, $category, $data) {
            $row = $company->assetCategories()->whereKey($category)->lockForUpdate()->firstOrFail();
            abort_unless($row->version === $data['version'], 409, 'Reference changed; reload');
            abort_unless($row->is_active, 409, 'Already archived');
            $row->is_active = false;
            $row->version++;
            $row->save();
            return $row->refresh();
        }, 3);
        return response()->json(['data' => $model]);
    }

}
