<?php

namespace App\Http\Controllers\Api;

use App\Domain\Inventory\CompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class AssetReferenceController extends Controller
{
    public function index(Request $request, Company $company, CompanyAccess $access): JsonResponse
    {
        $access->member($request, $company);
        return response()->json([
            'categories' => $company->assetCategories()->orderBy('name')->get(['id', 'code', 'name']),
            'accounting_locations' => $company->assetLocations()->where('kind', 'accounting')->orderBy('name')->get(['id', 'code', 'name']),
            'physical_locations' => $company->assetLocations()->where('kind', 'physical')->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function storeCategory(Request $request, Company $company, CompanyAccess $access): JsonResponse
    {
        $access->canWrite($request, $company);
        $data = $request->validate([
            'code' => ['required', 'string', 'min:1', 'max:60',
                Rule::unique('asset_categories', 'code')->where('company_id', $company->id)],
            'name' => ['required', 'string', 'min:2', 'max:200'],
        ]);
        $category = $company->assetCategories()->create([
            ...$data, 'id' => (string) Str::uuid(),
        ]);
        return response()->json($category->only(['id','code','name']), 201);
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
        $location = $company->assetLocations()->create([
            ...$data, 'id' => (string) Str::uuid(),
        ]);
        return response()->json($location->only(['id','kind','code','name']), 201);
    }
}