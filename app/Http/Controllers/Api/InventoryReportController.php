<?php

namespace App\Http\Controllers\Api;

use App\Domain\Inventory\CompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class InventoryReportController extends Controller
{
    private function snapshot(Request $request, Company $company, CompanyAccess $access): array
    {
        $access->member($request, $company);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['active','written_off','disposed'])],
            'facility_node_id' => ['nullable', 'uuid',
                Rule::exists('facility_nodes','id')->where('company_id',$company->id)],
            'responsible_person_id' => ['nullable','uuid',
                Rule::exists('responsible_people','id')->where('company_id',$company->id)],
        ]);
        $base = DB::table('assets as a')
            ->where('a.company_id', $company->id)
            ->whereNull('a.deleted_at');
        if (! empty($filters['status'])) {
            $base->where('a.status', $filters['status']);
        }
        if (! empty($filters['facility_node_id'])) {
            $base->where('a.facility_node_id', $filters['facility_node_id']);
        }
        if (! empty($filters['responsible_person_id'])) {
            $base->where('a.responsible_person_id', $filters['responsible_person_id']);
        }
        $group = static function ($query, string $id, string $name): array {
            $rows = $query->selectRaw($id.' as id, '.$name.' as name, COUNT(*) as total')
                ->groupBy($id, $name)->orderByDesc('total')->orderBy('name')
                ->limit(3001)->get();
            // An accounting summary must never silently omit group rows.
            abort_if($rows->count() > 3000, 413, 'Too many report groups; narrow the report');
            return $rows->map(fn ($row) => ['id' => $row->id, 'name' => $row->name ?: null,
                'total' => (int) $row->total])->all();
        };
        $byStatus = $group(clone $base, 'a.status', 'a.status');
        $byCategory = $group((clone $base)
            ->leftJoin('asset_categories as c','c.id','=','a.category_id'), 'a.category_id', 'c.name');
        $byFacility = $group((clone $base)
            ->leftJoin('facility_nodes as f','f.id','=','a.facility_node_id'), 'a.facility_node_id', 'f.name');
        $byPerson = $group((clone $base)
            ->leftJoin('responsible_people as p','p.id','=','a.responsible_person_id'), 'a.responsible_person_id', 'p.name');

        $warehouseRows = DB::table('stock_balances as b')->where('b.company_id',$company->id)
            ->leftJoin('facility_nodes as f','f.id','=','b.warehouse_node_id')
            ->selectRaw('b.warehouse_node_id as id, f.name as name, COUNT(DISTINCT b.stock_item_id) as items, SUM(b.quantity_milli) as quantity_milli')
            ->groupBy('b.warehouse_node_id','f.name')->orderBy('f.name')->limit(3001)->get();
        abort_if($warehouseRows->count() > 3000, 413, 'Too many warehouses; narrow the report');
        $stock = $warehouseRows->map(fn ($row) => [
            'id'=>$row->id,'name'=>$row->name,
            'items'=>(int)$row->items,'quantity_milli'=>(int)$row->quantity_milli,
        ])->all();
        $lowStock = DB::table('stock_items as i')->where('i.company_id', $company->id)
            ->where('i.is_active', true)->leftJoin('stock_balances as b', function ($join) {
                $join->on('b.stock_item_id','=','i.id')->on('b.company_id','=','i.company_id');
            })->groupBy('i.id','i.minimum_quantity_milli')
            ->havingRaw('COALESCE(SUM(b.quantity_milli),0) < i.minimum_quantity_milli')
            ->get(['i.id'])->count();

        $stocktakes = DB::table('inventory_sessions')->where('company_id',$company->id)
            ->selectRaw('status, COUNT(*) as sessions, COALESCE(SUM(total_items),0) as items, COALESCE(SUM(counted_items),0) as counted, COALESCE(SUM(discrepancies),0) as discrepancies')
            ->groupBy('status')->orderBy('status')->get()
            ->map(fn ($row) => ['status'=>$row->status,'sessions'=>(int)$row->sessions,
                'items'=>(int)$row->items,'counted'=>(int)$row->counted,
                'discrepancies'=>(int)$row->discrepancies])->all();

        return [
            'generated_at' => now()->toIso8601String(),
            'company' => ['id'=>$company->id,'name'=>$company->name],
            'filters' => $filters,
            'assets' => [
                'total' => (clone $base)->count(),
                'by_status'=>$byStatus, 'by_category'=>$byCategory,
                'by_facility'=>$byFacility, 'by_responsible'=>$byPerson,
            ],
            'warehouses' => ['total_items' => DB::table('stock_items')->where('company_id',$company->id)->count(),
                'low_stock_items'=>$lowStock,'by_warehouse'=>$stock],
            'stocktakes'=>$stocktakes,
        ];
    }

    public function summary(Request $request, Company $company, CompanyAccess $access): JsonResponse
    {
        return response()->json(['data'=>$this->snapshot($request,$company,$access)]);
    }

    public function csv(Request $request, Company $company, CompanyAccess $access): StreamedResponse
    {
        $report=$this->snapshot($request,$company,$access);
        $file='inventory-summary-'.now()->format('Ymd-His').'.csv';
        return response()->streamDownload(static function () use ($report): void {
            $output = fopen('php://output','wb');
            fwrite($output, "\xEF\xBB\xBF");
            $safe = static function ($value): string {
                $text=(string)($value ?? '');
                return preg_match('/^\s*[=+@\-]/u',$text) ? "'".$text : $text;
            };
            $write = static function (array $row) use ($output,$safe): void {
                fputcsv($output,array_map($safe,$row),';','"', '\\');
            };
            $write(['ITP Inventory','Summary report',$report['generated_at']]);
            $write(['Company',$report['company']['name']]);
            $write([]);
            $write(['Assets','Total',$report['assets']['total']]);
            foreach (['by_status'=>'Status','by_category'=>'Category','by_facility'=>'Facility','by_responsible'=>'Responsible person'] as $key=>$label) {
                $write([]);
                $write([$label,'Name','Count']);
                foreach ($report['assets'][$key] as $row) $write([$row['id'],$row['name'] ?? 'Unassigned',$row['total']]);
            }
            $write([]);
            $write(['Warehouse','Items','Quantity / 1000']);
            foreach ($report['warehouses']['by_warehouse'] as $row) {
                $write([$row['name'],$row['items'],number_format($row['quantity_milli']/1000,3,'.','')]);
            }
            $write([]);
            $write(['Stocktake status','Sessions','Expected','Counted','Discrepancies']);
            foreach ($report['stocktakes'] as $row) {
                $write([$row['status'],$row['sessions'],$row['items'],$row['counted'],$row['discrepancies']]);
            }
            fclose($output);
        },$file,['Content-Type'=>'text/csv; charset=UTF-8','Cache-Control'=>'private, no-store']);
    }
}
