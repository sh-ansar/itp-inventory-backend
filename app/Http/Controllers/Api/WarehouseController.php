<?php

namespace App\Http\Controllers\Api;

use App\Domain\Inventory\CompanyAccess;
use App\Domain\Inventory\WarehouseLedger;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class WarehouseController extends Controller
{
    public function items(Request $request, Company $company, CompanyAccess $access): JsonResponse
    {
        $access->member($request, $company);
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'active' => ['nullable', Rule::in(['0', '1'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $q = $company->stockItems()->withSum('balances', 'quantity_milli');
        if (! empty($data['search'])) {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($data['search'])));
            $pattern = '%'.$escaped.'%';
            $q->where(fn ($query) => $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(code) LIKE ? ESCAPE '!'", [$pattern]));
        }
        if (isset($data['active'])) {
            $q->where('is_active', $data['active'] === '1');
        }

        return response()->json($q->orderBy('name')->paginate($data['per_page'] ?? 25));
    }

    public function storeItem(Request $request, Company $company, CompanyAccess $access): JsonResponse
    {
        $access->canWrite($request, $company);
        $data = $request->validate([
            'code' => ['required', 'string', 'min:1', 'max:80',
                Rule::unique('stock_items', 'code')->where('company_id', $company->id)],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'unit' => ['required', Rule::in(WarehouseLedger::UNITS)],
            'category_id' => ['nullable', 'uuid',
                Rule::exists('asset_categories', 'id')->where('company_id', $company->id)],
            'minimum_quantity' => ['nullable', 'regex:/\A[0-9]{1,6}(?:\.[0-9]{1,3})?\z/D'],
        ]);
        $minimum = WarehouseLedger::milli((string) ($data['minimum_quantity'] ?? '0.000'), $data['unit'], true);
        // A zero minimum is allowed even though transaction quantities must be positive.
        $model = $company->stockItems()->create([
            'id' => (string) Str::uuid(),
            'code' => trim($data['code']), 'name' => trim($data['name']),
            'unit' => $data['unit'], 'category_id' => $data['category_id'] ?? null,
            'minimum_quantity_milli' => $minimum,
        ]);

        return response()->json(['data' => $model->refresh()], 201);
    }

    public function balances(Request $request, Company $company, CompanyAccess $access): JsonResponse
    {
        $access->member($request, $company);
        $data = $request->validate([
            'warehouse_node_id' => ['required', 'uuid',
                Rule::exists('facility_nodes', 'id')->where('company_id', $company->id)
                    ->where('node_type', 'warehouse')],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $results = $company->stockItems()
            ->join('stock_balances', 'stock_balances.stock_item_id', '=', 'stock_items.id')
            ->where('stock_balances.company_id', $company->id)
            ->where('stock_balances.warehouse_node_id', $data['warehouse_node_id'])
            ->where('stock_balances.quantity_milli', '>', 0)
            ->select([
                'stock_items.id', 'stock_items.code', 'stock_items.name',
                'stock_items.unit', 'stock_items.minimum_quantity_milli',
                'stock_balances.quantity_milli',
            ])->orderBy('stock_items.name')
            ->paginate($data['per_page'] ?? 25);

        return response()->json($results);
    }

    public function transactions(Request $request, Company $company, CompanyAccess $access): JsonResponse
    {
        $access->member($request, $company);
        $data = $request->validate([
            'stock_item_id' => ['nullable', 'uuid',
                Rule::exists('stock_items', 'id')->where('company_id', $company->id)],
            'warehouse_node_id' => ['nullable', 'uuid',
                Rule::exists('facility_nodes', 'id')->where('company_id', $company->id)
                    ->where('node_type', 'warehouse')],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $q = $company->stockTransactions();
        if (isset($data['stock_item_id'])) {
            $q->where('stock_item_id', $data['stock_item_id']);
        }
        if (isset($data['warehouse_node_id'])) {
            $q->where(fn ($query) => $query
                ->where('source_warehouse_node_id', $data['warehouse_node_id'])
                ->orWhere('destination_warehouse_node_id', $data['warehouse_node_id']));
        }

        return response()->json($q->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($data['per_page'] ?? 25));
    }

    public function postTransaction(
        Request $request, Company $company, CompanyAccess $access, WarehouseLedger $ledger
    ): JsonResponse {
        $access->canWrite($request, $company);
        $data = $request->validate([
            'stock_item_id' => ['required', 'uuid',
                Rule::exists('stock_items', 'id')->where('company_id', $company->id)],
            'operation' => ['required', Rule::in(WarehouseLedger::OPERATIONS)],
            'warehouse_node_id' => ['required', 'uuid',
                Rule::exists('facility_nodes', 'id')->where('company_id', $company->id)
                    ->where('node_type', 'warehouse')],
            'target_warehouse_node_id' => ['nullable', 'uuid',
                Rule::exists('facility_nodes', 'id')->where('company_id', $company->id)
                    ->where('node_type', 'warehouse')],
            'quantity' => ['required', 'regex:/\A[0-9]{1,6}(?:\.[0-9]{1,3})?\z/D'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        return response()->json(['data' => $ledger->record($company,$request->user(),$data)],201);
    }
}
