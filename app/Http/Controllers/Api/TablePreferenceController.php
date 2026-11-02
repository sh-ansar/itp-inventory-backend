<?php

namespace App\Http\Controllers\Api;

use App\Domain\Inventory\CompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class TablePreferenceController extends Controller
{
    private const VIEWS = [
        'assets' => ['inventory_number', 'name', 'category_name', 'facility_node_name',
            'responsible_person_name', 'physical_location_name', 'status', 'actions'],
        'stocktakes' => ['name', 'status', 'progress', 'discrepancies', 'created_at', 'created_by_name', 'actions'],
        'disposals' => ['asset', 'name', 'status', 'order_number', 'documents', 'updated_at', 'actions'],
        'warehouse_balances' => ['code', 'name', 'quantity_milli', 'unit', 'minimum_quantity_milli'],
        'warehouse_items' => ['code', 'name', 'unit', 'balances_sum_quantity_milli', 'minimum_quantity_milli', 'actions'],
        'warehouse_history' => ['created_at', 'operation', 'stock_item_id', 'quantity_milli', 'actor_name', 'reason'],
        'people' => ['code', 'name', 'position', 'email', 'source', 'is_active', 'assets_count', 'actions'],
    ];

    private const SORTABLE = [
        'assets' => ['inventory_number', 'name', 'created_at', 'updated_at'],
        'stocktakes' => ['created_at', 'name'],
        'disposals' => ['updated_at', 'name'],
        'warehouse_balances' => ['code', 'name'],
        'warehouse_items' => ['code', 'name'],
        'warehouse_history' => ['created_at'],
        'people' => ['name'],
    ];

    private function viewKey(string $view): string
    {
        abort_unless(array_key_exists($view, self::VIEWS), 404);
        return $view;
    }

    public function show(Request $request, Company $company, string $view, CompanyAccess $access): JsonResponse
    {
        $access->member($request, $company);
        $view = $this->viewKey($view);
        $data = DB::table('inventory_table_preferences')
            ->where('company_id', $company->id)
            ->where('user_id', $request->user()->id)
            ->where('view_key', $view)
            ->first();

        return response()->json([
            'data' => [
                'view' => $view,
                'settings' => $data ? json_decode($data->settings, true, 512, JSON_THROW_ON_ERROR) : null,
                'updated_at' => $data?->updated_at,
            ],
        ]);
    }

    public function update(Request $request, Company $company, string $view, CompanyAccess $access): JsonResponse
    {
        $access->member($request, $company);
        $view = $this->viewKey($view);
        $validColumns = self::VIEWS[$view];
        $values = $request->validate([
            'settings' => ['required', 'array:search,status,facility_node_id,responsible_person_id,warehouse_node_id,statuses,facility_node_ids,responsible_person_ids,per_page,sort_key,sort_direction,hidden_columns,column_order'],
            'settings.search' => ['nullable', 'string', 'max:120'],
            'settings.status' => ['nullable', Rule::in(['', 'active', 'written_off', 'disposed', 'draft', 'submitted', 'approved',
                'in_progress', 'completed', '1', '0'])],
            'settings.facility_node_id' => ['nullable','uuid',
                Rule::exists('facility_nodes', 'id')->where('company_id', $company->id)],
            'settings.responsible_person_id' => ['nullable','uuid',
                Rule::exists('responsible_people', 'id')->where('company_id', $company->id)],
            'settings.warehouse_node_id' => ['nullable','uuid',
                Rule::exists('facility_nodes', 'id')->where('company_id', $company->id)],
            'settings.statuses' => ['sometimes','array','max:30'],
            'settings.statuses.*' => ['required','string','distinct',Rule::in(['active','written_off','disposed'])],
            'settings.facility_node_ids' => ['sometimes','array','max:30'],
            'settings.facility_node_ids.*' => ['required','uuid','distinct',Rule::exists('facility_nodes','id')->where('company_id',$company->id)],
            'settings.responsible_person_ids' => ['sometimes','array','max:30'],
            'settings.responsible_person_ids.*' => ['required','uuid','distinct',Rule::exists('responsible_people','id')->where('company_id',$company->id)],
            'settings.per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
            'settings.sort_key' => ['nullable', Rule::in(self::SORTABLE[$view])],
            'settings.sort_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'settings.hidden_columns' => ['sometimes', 'array', 'max:20'],
            'settings.hidden_columns.*' => ['string', 'distinct', Rule::in($validColumns)],
            'settings.column_order' => ['sometimes', 'array', 'max:20'],
            'settings.column_order.*' => ['string', 'distinct', Rule::in($validColumns)],
        ]);
        $settings = $values['settings'];
        // Critical columns and row actions cannot be hidden through preferences.
        $settings['hidden_columns'] = array_values(array_diff(
            $settings['hidden_columns'] ?? [], ['inventory_number', 'name', 'actions']
        ));
        $record = ['company_id' => $company->id,
            'user_id' => $request->user()->id, 'view_key' => $view];
        DB::table('inventory_table_preferences')->updateOrInsert($record,
            ['settings' => json_encode($settings, JSON_THROW_ON_ERROR),
                'updated_at' => now(), 'created_at' => now()]);

        return response()->json(['data' => ['view' => $view, 'settings' => $settings]]);
    }

    public function destroy(Request $request, Company $company, string $view, CompanyAccess $access): \Illuminate\Http\Response
    {
        $access->member($request, $company);
        DB::table('inventory_table_preferences')
            ->where('company_id', $company->id)
            ->where('user_id', $request->user()->id)
            ->where('view_key', $this->viewKey($view))
            ->delete();

        return response()->noContent();
    }
}
