<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class AccountingIntegrationController extends Controller
{
    public function metadata(Request $request, Company $company): JsonResponse
    {
        return response()->json([
            'data'=> [
                'api_version'=>'1.0',
                'company_id'=>$company->id,
                'system_code'=>$request->attributes->get('inventory.integration.system'),
                'direction'=>'read_only_with_import_preview',
                'capabilities'=>['assets:read','references:read','stock:read','imports:preview'],
                'asset_fields'=>['id','public_id','inventory_number','name','serial_number',
                    'status','category_id','accounting_location_id','physical_location_id',
                    'facility_node_id','responsible_person_id','version','updated_at','deleted_at'],
                'quantity_scale'=>1000,
                'clock'=>'ISO-8601 UTC',
                'import_enabled'=>false,
            ],
        ]);
    }

    public function assets(Request $request, Company $company): JsonResponse
    {
        $params=$request->validate([
            'per_page'=>['nullable','integer','min:1','max:100'],
            'after_id'=>['nullable','uuid'],
            'updated_since'=>['nullable','date'],
            'updated_until'=>['nullable','date'],
        ]);
        $cutoff = $params['updated_until'] ?? now()->toIso8601String();
        $limit = (int) ($params['per_page'] ?? 100);
        $system = (string) $request->attributes->get('inventory.integration.system');
        $query=DB::table('assets as a')->where('a.company_id',$company->id)
            ->leftJoin('inventory_external_mappings as m',function ($join) use ($company,$system): void {
                $join->on('m.internal_id','=','a.id')
                    ->where('m.company_id','=',$company->id)
                    ->where('m.system_code','=',$system)
                    ->where('m.entity_type','=','asset');
            })
            ->select(['a.id','a.public_id','a.inventory_number','a.name','a.serial_number',
                'a.status','a.category_id','a.accounting_location_id','a.physical_location_id',
                'a.facility_node_id','a.responsible_person_id','a.version','a.updated_at','a.deleted_at',
                'm.external_id']);
        if (!empty($params['after_id'])) $query->where('a.id','>',$params['after_id']);
        if (!empty($params['updated_since'])) $query->where('a.updated_at','>=',$params['updated_since']);
        $query->where('a.updated_at','<=',$cutoff);
        $rows=$query->orderBy('a.id')->limit($limit+1)->get();
        $more=$rows->count()>$limit;
        $data=$rows->take($limit)->values();

        return response()->json(['data'=>$data,'meta'=>[
            'count'=>$data->count(),
            'next_after_id'=>$more ? $data->last()?->id : null,
            'updated_until'=>$cutoff,
            'schema_version'=>'1.0',
        ]],200,['Cache-Control'=>'private, no-store']);
    }

    public function references(Request $request, Company $company): JsonResponse
    {
        $id=$company->id;
        $directory=static function (string $table,string $sort,array $columns) use ($id) {
            $query=DB::table($table)->where('company_id',$id);
            // Never return a silently incomplete reference catalog to accounting.
            abort_if((clone $query)->count()>3000,413,
                'Reference catalog too large for v1; use a paginated integration contract');
            return $query->orderBy($sort)->get($columns);
        };
        return response()->json(['data'=>[
            'categories'=>$directory('asset_categories','code',['id','code','name']),
            'locations'=>$directory('asset_locations','code',['id','code','name','kind']),
            'responsible_people'=>$directory('responsible_people','name',['id','name']),
            'facility_nodes'=>$directory('facility_nodes','name',['id','parent_id','name','node_type']),
        ]],200,['Cache-Control'=>'private, no-store']);
    }

    public function stock(Request $request, Company $company): JsonResponse
    {
        $params=$request->validate(['after_id'=>['nullable','integer','min:0'],
            'per_page'=>['nullable','integer','min:1','max:100']]);
        $limit=(int)($params['per_page']??100);
        $query=DB::table('stock_balances as b')->where('b.company_id',$company->id)
            ->join('stock_items as s',function ($join): void {
                $join->on('s.id','=','b.stock_item_id')->on('s.company_id','=','b.company_id');
            })
            ->select(['b.id','b.stock_item_id','b.warehouse_node_id','b.quantity_milli',
                'b.updated_at','s.code','s.name','s.unit','s.version']);
        if (isset($params['after_id'])) $query->where('b.id','>',$params['after_id']);
        $rows=$query->orderBy('b.id')->limit($limit+1)->get();
        $more=$rows->count()>$limit;
        $data=$rows->take($limit)->values();

        return response()->json(['data'=>$data,'meta'=>[
            'count'=>$data->count(),
            'next_after_id'=>$more ? $data->last()?->id : null,
            'quantity_scale'=>1000,
        ]],200,['Cache-Control'=>'private, no-store']);
    }

    public function preview(Request $request, Company $company): JsonResponse
    {
        $params=$request->validate([
            'mode'=>['required',Rule::in(['dry_run'])],
            'entity_type'=>['required',Rule::in(['asset'])],
            'records'=>['required','array','min:1','max:100'],
            'records.*'=>['required','array:external_id,inventory_number,name'],
            'records.*.external_id'=>['required','string','min:1','max:180'],
            'records.*.inventory_number'=>['required','string','min:1','max:100'],
            'records.*.name'=>['required','string','min:1','max:255'],
        ]);
        $system=(string) $request->attributes->get('inventory.integration.system');
        $externalIds=[];$numbers=[];
        $results=[];
        foreach ($params['records'] as $index=>$record) {
            $externalId=trim($record['external_id']);
            $number=trim($record['inventory_number']);
            $issues=[];
            if (isset($externalIds[$externalId])) $issues[]='DUPLICATE_EXTERNAL_ID_IN_BATCH';
            if (isset($numbers[$number])) $issues[]='DUPLICATE_INVENTORY_NUMBER_IN_BATCH';
            $externalIds[$externalId]=true;$numbers[$number]=true;
            $mapped=DB::table('inventory_external_mappings')
                ->where('company_id',$company->id)->where('system_code',$system)
                ->where('entity_type','asset')->where('external_id',$externalId)->first();
            $asset=$company->assets()->withTrashed()->where('inventory_number',$number)->first();
            if ($mapped && (! $asset || $mapped->internal_id !== $asset->id)) {
                $issues[]='MAPPING_CONFLICT';
            }
            $decision=$issues ? 'conflict' : ($mapped ? 'mapped' : ($asset
                ? 'manual_match_required' : 'new_candidate'));
            $results[]=[
                'row'=>$index,
                'external_id'=>$externalId,
                'inventory_number'=>$number,
                'decision'=>$decision,
                'matched_asset_id'=>$asset?->id,
                'issues'=>$issues,
            ];
        }
        return response()->json([
            'dry_run'=>true,
            'written'=>0,
            'company_id'=>$company->id,
            'system_code'=>$system,
            'data'=>$results,
            'summary'=>array_count_values(array_column($results,'decision')),
        ],200,['Cache-Control'=>'private, no-store']);
    }
}
