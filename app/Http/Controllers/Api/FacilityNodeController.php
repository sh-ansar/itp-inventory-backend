<?php

namespace App\Http\Controllers\Api;

use App\Domain\Inventory\CompanyAccess;
use App\Domain\Inventory\FacilityHierarchy;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class FacilityNodeController extends Controller
{
    private function rules(Company $company, ?string $id = null): array
    {
        return [
            'code' => ['required', 'string', 'min:1', 'max:80',
                Rule::unique('facility_nodes', 'code')->where('company_id', $company->id)
                    ->ignore($id, 'id')],
            'name' => ['required', 'string', 'min:2', 'max:200'],
            'node_type' => ['required', Rule::in(FacilityHierarchy::TYPES)],
            'parent_id' => ['nullable', 'uuid',
                Rule::exists('facility_nodes', 'id')->where('company_id', $company->id)],
            'address' => ['nullable', 'string', 'max:500'],
            'floor_number' => ['nullable', 'integer', 'between:-15,250'],
            'responsible_person_id' => ['nullable', 'uuid',
                Rule::exists('responsible_people', 'id')
                    ->where('company_id', $company->id)
                    ->where('is_active', true)],
            'sort_order' => ['nullable', 'integer', 'between:0,999999'],
            ...($id ? ['version' => ['required', 'integer', 'min:1']] : []),
        ];
    }

    public function index(Request $request, Company $company, CompanyAccess $access): JsonResponse
    {
        $access->member($request, $company);
        $count = $company->facilityNodes()->count();
        // Never silently drop branches from an operational map.
        abort_if($count > 3000, 413, 'Facility hierarchy is too large for one map; use a smaller scope');
        $nodes = $company->facilityNodes()
            ->with(['responsiblePerson:id,name'])
            ->withCount(['assets' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn ($node) => [
                ...$node->only([
                    'id', 'company_id', 'parent_id', 'code', 'name', 'node_type',
                    'address', 'floor_number', 'responsible_person_id', 'sort_order',
                    'version', 'created_at', 'updated_at',
                ]),
                'responsible_person_name' => $node->responsiblePerson?->name,
                'direct_asset_count' => $node->assets_count,
            ]);

        return response()->json(['data' => $nodes, 'total' => $count]);
    }

    public function show(
        Request $request, Company $company, string $node, CompanyAccess $access,
        FacilityHierarchy $hierarchy
    ): JsonResponse {
        $access->member($request, $company);
        $model = $company->facilityNodes()->whereKey($node)
            ->with('responsiblePerson:id,name')->withCount('assets')->firstOrFail();

        return response()->json([
            'data' => [
                ...$model->toArray(),
                'direct_asset_count' => $model->assets_count,
                'subtree_node_ids' => $hierarchy->descendants($company, $node),
            ],
        ]);
    }

    public function store(
        Request $request, Company $company, CompanyAccess $access, FacilityHierarchy $hierarchy
    ): JsonResponse {
        $access->canWrite($request, $company);
        $data = $request->validate($this->rules($company));
        if (isset($data['address']) && ! in_array($data['node_type'], [
            'site', 'building', 'pavilion', 'warehouse',
        ], true)) {
            // Sublevels inherit the parent address; avoid contradictory room addresses.
            $data['address'] = null;
        }
        $model = $hierarchy->create($company, $request->user(), $data);

        return response()->json(['data' => $model], 201);
    }

    public function update(
        Request $request, Company $company, string $node,
        CompanyAccess $access, FacilityHierarchy $hierarchy
    ): JsonResponse {
        $access->canWrite($request, $company);
        $company->facilityNodes()->whereKey($node)->firstOrFail();
        $data = $request->validate($this->rules($company, $node));
        if (isset($data['address']) && ! in_array($data['node_type'], [
            'site', 'building', 'pavilion', 'warehouse',
        ], true)) {
            $data['address'] = null;
        }
        $model = $hierarchy->update($company, $node, $request->user(), $data);

        return response()->json(['data' => $model]);
    }

    public function events(
        Request $request, Company $company, string $node, CompanyAccess $access
    ): JsonResponse {
        $access->member($request, $company);
        $model = $company->facilityNodes()->whereKey($node)->firstOrFail();

        return response()->json(['data' => $model->events()->orderByDesc('version')->limit(100)->get()]);
    }
}
