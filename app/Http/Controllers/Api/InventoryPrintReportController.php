<?php

namespace App\Http\Controllers\Api;

use App\Domain\Inventory\CompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Tenant-scoped, read-only source for printable inventory documents.
 * Never silently print an incomplete register; no mutations, public links, or signatures.
 */
final class InventoryPrintReportController extends Controller
{
    private const KINDS = [
        'asset-register', 'by-facility', 'by-custodian',
        'warehouse-balances', 'stocktake-act', 'disposal-register',
    ];
    private const MAX_ROWS = 2000;

    public function show(Request $request, Company $company, string $kind, CompanyAccess $access): JsonResponse
    {
        $access->member($request, $company);
        abort_unless(in_array($kind, self::KINDS, true), 404);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'written_off', 'disposed'])],
            'disposal_status' => ['nullable', Rule::in(['draft','submitted','approved','disposed'])],
            'facility_node_id' => ['nullable','uuid',
                Rule::exists('facility_nodes','id')->where('company_id',$company->id)],
            'responsible_person_id' => ['nullable','uuid',
                Rule::exists('responsible_people','id')->where('company_id',$company->id)],
            'warehouse_node_id' => ['nullable','uuid',
                Rule::exists('facility_nodes','id')->where('company_id',$company->id)
                    ->where('node_type','warehouse')],
            'session_id' => [$kind === 'stocktake-act' ? 'required' : 'nullable','uuid',
                Rule::exists('inventory_sessions','id')->where('company_id',$company->id)],
        ]);

        $context = [];
        if ($kind === 'warehouse-balances') {
            $rows = $this->warehouseRows($company, $filters);
        } elseif ($kind === 'stocktake-act') {
            [$rows,$context] = $this->stocktakeRows($company, $filters['session_id']);
        } elseif ($kind === 'disposal-register') {
            $rows = $this->disposalRows($company, $filters);
        } else {
            $rows = $this->assetRows($company, $filters);
        }

        return response()->json([
            'data' => [
                'kind' => $kind,
                'generated_at' => now()->toIso8601String(),
                'company' => ['id' => $company->id, 'name' => $company->name],
                'filters' => $filters,
                'context' => $context,
                'row_count' => count($rows),
                'rows' => $rows,
                'limit' => self::MAX_ROWS,
            ],
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    /** Tenant ownership is checked before fetching, and bounded BEFORE rendering. */
    private function limited($query): array
    {
        $rows = $query->limit(self::MAX_ROWS + 1)->get();
        abort_if($rows->count() > self::MAX_ROWS, 413,
            'Print register contains more than 2000 rows. Narrow the filters.');
        return $rows->map(static fn ($item) => (array) $item)->all();
    }

    private function assetRows(Company $company, array $filters): array
    {
        $q = DB::table('assets as a')->where('a.company_id', $company->id)
            ->whereNull('a.deleted_at')
            ->leftJoin('asset_categories as c', function ($join) {
                $join->on('c.id','=','a.category_id')->on('c.company_id','=','a.company_id');
            })
            ->leftJoin('facility_nodes as f', function ($join) {
                $join->on('f.id','=','a.facility_node_id')->on('f.company_id','=','a.company_id');
            })
            ->leftJoin('responsible_people as p', function ($join) {
                $join->on('p.id','=','a.responsible_person_id')->on('p.company_id','=','a.company_id');
            })
            ->select([
                'a.id','a.inventory_number','a.name','a.serial_number','a.status',
                'a.description','a.category_id','c.name as category_name',
                'a.facility_node_id','f.name as facility_name','f.address as facility_address',
                'a.responsible_person_id','p.name as responsible_person_name',
                'p.position as responsible_person_position',
            ]);
        $this->applyAssetFilters($q,$filters,'a');
        return $this->limited($q->orderBy('a.inventory_number')->orderBy('a.id'));
    }

    private function applyAssetFilters($query, array $filters, string $alias): void
    {
        if (!empty($filters['status'])) $query->where($alias.'.status',$filters['status']);
        if (!empty($filters['facility_node_id'])) {
            $query->where($alias.'.facility_node_id',$filters['facility_node_id']);
        }
        if (!empty($filters['responsible_person_id'])) {
            $query->where($alias.'.responsible_person_id',$filters['responsible_person_id']);
        }
        if (!empty($filters['search'])) {
            $term = str_replace(['!', '%', '_'], ['!!', '!%', '!_'],
                mb_strtolower(trim($filters['search'])));
            $pattern = '%'.$term.'%';
            $query->where(static fn ($q) => $q
                ->whereRaw("LOWER($alias.inventory_number) LIKE ? ESCAPE '!'",[$pattern])
                ->orWhereRaw("LOWER($alias.name) LIKE ? ESCAPE '!'",[$pattern])
                ->orWhereRaw("LOWER($alias.serial_number) LIKE ? ESCAPE '!'",[$pattern]));
        }
    }

    private function warehouseRows(Company $company, array $filters): array
    {
        $q = DB::table('stock_balances as b')->where('b.company_id', $company->id)
            ->join('stock_items as i', function ($join) {
                $join->on('i.id','=','b.stock_item_id')->on('i.company_id','=','b.company_id');
            })
            ->join('facility_nodes as f', function ($join) {
                $join->on('f.id','=','b.warehouse_node_id')->on('f.company_id','=','b.company_id');
            })
            ->select([
                'b.id','b.warehouse_node_id','f.name as warehouse_name',
                'f.address as warehouse_address',
                'i.code','i.name','i.unit','i.is_active',
                'b.quantity_milli','i.minimum_quantity_milli',
            ]);
        if (!empty($filters['warehouse_node_id'])) {
            $q->where('b.warehouse_node_id', $filters['warehouse_node_id']);
        }
        if (!empty($filters['search'])) {
            $term = str_replace(['!', '%', '_'], ['!!','!%','!_'],
                mb_strtolower(trim($filters['search'])));
            $pattern = '%'.$term.'%';
            $q->where(static fn ($query) => $query
                ->whereRaw("LOWER(i.code) LIKE ? ESCAPE '!'",[$pattern])
                ->orWhereRaw("LOWER(i.name) LIKE ? ESCAPE '!'",[$pattern]));
        }
        return $this->limited($q->orderBy('f.name')->orderBy('i.code')->orderBy('b.id'));
    }

    private function stocktakeRows(Company $company, string $sessionId): array
    {
        $session = DB::table('inventory_sessions')
            ->where('company_id',$company->id)->where('id',$sessionId)->first();
        abort_unless($session,404);
        $q = DB::table('inventory_session_items')
            ->where('company_id',$company->id)->where('session_id',$sessionId)
            ->select([
                'id','inventory_number','name','is_counted',
                'expected_location_id','expected_location_name',
                'expected_facility_node_id','expected_facility_node_name',
                'actual_location_id','actual_location_name',
                'actual_facility_node_id','actual_facility_node_name',
                'note','counted_by_name','counted_at',
            ]);
        $rows = $this->limited($q->orderBy('inventory_number')->orderBy('id'));
        foreach ($rows as &$row) {
            $isFacility = !empty($row['expected_facility_node_id']);
            $expected = $isFacility ? $row['expected_facility_node_id'] : $row['expected_location_id'];
            $actual = $isFacility ? $row['actual_facility_node_id'] : $row['actual_location_id'];
            $row['expected_name'] = $isFacility
                ? $row['expected_facility_node_name'] : $row['expected_location_name'];
            $row['actual_name'] = $isFacility
                ? $row['actual_facility_node_name'] : $row['actual_location_name'];
            $row['is_discrepancy'] = (bool)$row['is_counted'] && (string)($expected ?? '') !== (string)($actual ?? '');
        }
        unset($row);

        return [$rows,[
            'session_id' => $session->id,
            'session_name' => $session->name,
            'session_status' => $session->status,
            'scope_name' => $session->scope_facility_node_name
                ?: $session->scope_location_name ?: null,
            'total_items' => (int)$session->total_items,
            'counted_items' => (int)$session->counted_items,
            'discrepancies' => (int)$session->discrepancies,
            'created_by_name' => $session->created_by_name,
            'completed_by_name' => $session->completed_by_name,
            'completed_at' => $session->completed_at,
            'created_at' => $session->created_at,
        ]];
    }

    private function disposalRows(Company $company, array $filters): array
    {
        $q = DB::table('asset_disposal_cases as d')
            ->where('d.company_id',$company->id)
            ->join('assets as a', function ($join) {
                $join->on('a.id','=','d.asset_id')->on('a.company_id','=','d.company_id');
            })
            ->leftJoin('facility_nodes as f', function ($join) {
                $join->on('f.id','=','a.facility_node_id')->on('f.company_id','=','a.company_id');
            })
            ->leftJoin('responsible_people as p', function ($join) {
                $join->on('p.id','=','a.responsible_person_id')->on('p.company_id','=','a.company_id');
            })
            ->select([
                'd.id','d.status as case_status','d.reason','d.order_number','d.order_date',
                'd.disposal_act_number','d.disposal_date','d.disposal_method',
                'd.updated_at','a.inventory_number','a.name','a.serial_number',
                'a.status as asset_status','a.facility_node_id','f.name as facility_name',
                'a.responsible_person_id','p.name as responsible_person_name',
            ]);
        if (!empty($filters['disposal_status'])) $q->where('d.status',$filters['disposal_status']);
        if (!empty($filters['status'])) $q->where('a.status',$filters['status']);
        if (!empty($filters['facility_node_id'])) {
            $q->where('a.facility_node_id',$filters['facility_node_id']);
        }
        if (!empty($filters['responsible_person_id'])) {
            $q->where('a.responsible_person_id',$filters['responsible_person_id']);
        }
        if (!empty($filters['search'])) {
            $term = str_replace(['!', '%', '_'], ['!!','!%','!_'],
                mb_strtolower(trim($filters['search'])));
            $pattern = '%'.$term.'%';
            $q->where(static fn ($query) => $query
                ->whereRaw("LOWER(a.inventory_number) LIKE ? ESCAPE '!'",[$pattern])
                ->orWhereRaw("LOWER(a.name) LIKE ? ESCAPE '!'",[$pattern]));
        }
        return $this->limited($q->orderBy('d.updated_at','desc')->orderBy('d.id'));
    }
}
