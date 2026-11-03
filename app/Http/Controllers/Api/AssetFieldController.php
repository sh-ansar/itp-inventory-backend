<?php

namespace App\Http\Controllers\Api;

use App\Domain\Inventory\AssetAuditWriter;
use App\Domain\Inventory\CompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AssetFieldController extends Controller
{
    private const TYPES = ['text','number','date','boolean','select'];

    public function index(Request $request, Company $company, CompanyAccess $access): JsonResponse
    {
        $access->member($request, $company);
        $data=$request->validate([
            'category_id'=>['required','uuid',Rule::exists('asset_categories','id')->where('company_id',$company->id)],
            'include_archived'=>['sometimes','boolean'],
        ]);
        $query=DB::table('asset_field_definitions')->where('company_id',$company->id)
            ->where('category_id',$data['category_id']);
        if(!$request->boolean('include_archived')) $query->where('is_active',true);
        return response()->json(['data'=>$query->orderBy('sort_order')->orderBy('code')->get()
            ->map(function($row){
                $row->options=$row->options?json_decode($row->options,true):[];
                return $row;
            })]);
    }

    public function store(Request $request, Company $company, CompanyAccess $access): JsonResponse
    {
        $access->canWrite($request,$company);
        $data=$request->validate([
            'category_id'=>['required','uuid',Rule::exists('asset_categories','id')->where('company_id',$company->id)],
            'code'=>['required','string','max:60','regex:/\A[a-z][a-z0-9_]*\z/D'],
            'label_ru'=>['required','string','max:160'],
            'label_en'=>['nullable','string','max:160'],
            'label_ka'=>['nullable','string','max:160'],
            'field_type'=>['required',Rule::in(self::TYPES)],
            'options'=>['sometimes','array','max:50'],
            'options.*'=>['string','max:100','distinct'],
            'required'=>['sometimes','boolean'],
            'sort_order'=>['sometimes','integer','min:0','max:10000'],
        ]);
        if($data['field_type']==='select'&&empty($data['options'])){
            throw ValidationException::withMessages(['options'=>['Select fields need options']]);
        }
        if($data['field_type']!=='select'&&!empty($data['options'])){
            throw ValidationException::withMessages(['options'=>['Only select fields have options']]);
        }
        $id=(string) Str::uuid();
        $duplicate=DB::table('asset_field_definitions')->where('company_id',$company->id)
            ->where('category_id',$data['category_id'])->where('code',$data['code'])->exists();
        if($duplicate)throw ValidationException::withMessages(['code'=>['Code already exists for this category']]);
        DB::table('asset_field_definitions')->insert([
            'id'=>$id,'company_id'=>$company->id,'category_id'=>$data['category_id'],
            'code'=>$data['code'],'label_ru'=>trim($data['label_ru']),
            'label_en'=>trim($data['label_en']??''),'label_ka'=>trim($data['label_ka']??''),
            'field_type'=>$data['field_type'],'options'=>json_encode($data['options']??[]),
            'required'=>$data['required']??false,'sort_order'=>$data['sort_order']??0,
            'is_active'=>true,'version'=>1,'created_at'=>now(),'updated_at'=>now(),
        ]);
        return response()->json(['data'=>DB::table('asset_field_definitions')->where('id',$id)->first()],201);
    }

    public function update(Request $request, Company $company, string $field, CompanyAccess $access): JsonResponse
    {
        $access->canWrite($request,$company);
        $data=$request->validate([
            'version'=>['required','integer','min:1'],
            'label_ru'=>['required','string','max:160'],
            'label_en'=>['nullable','string','max:160'],
            'label_ka'=>['nullable','string','max:160'],
            'sort_order'=>['sometimes','integer','min:0','max:10000'],
            'is_active'=>['sometimes','boolean'],
        ]);
        $updated=DB::transaction(function() use($company,$field,$data){
            $row=DB::table('asset_field_definitions')->where('company_id',$company->id)
                ->where('id',$field)->lockForUpdate()->first();
            abort_unless($row,404);
            abort_unless($row->version===$data['version'],409,'Field changed; reload');
            $changes=[
                'label_ru'=>trim($data['label_ru']),
                'label_en'=>trim($data['label_en']??''),
                'label_ka'=>trim($data['label_ka']??''),
                'sort_order'=>$data['sort_order']??$row->sort_order,
                'is_active'=>$data['is_active']??$row->is_active,
                'version'=>$row->version+1,'updated_at'=>now(),
            ];
            DB::table('asset_field_definitions')->where('id',$field)->update($changes);
            return DB::table('asset_field_definitions')->where('id',$field)->first();
        });
        return response()->json(['data'=>$updated]);
    }

    public function values(Request $request, Company $company, string $asset, CompanyAccess $access): JsonResponse
    {
        $access->member($request,$company);
        $model=$company->assets()->whereKey($asset)->firstOrFail();
        $rows=DB::table('asset_field_values')->where('company_id',$company->id)
            ->where('asset_id',$model->id)->get(['field_id','value']);
        $values=[];
        foreach($rows as $row)$values[$row->field_id]=json_decode($row->value,true);
        return response()->json(['data'=>$values,'asset_version'=>$model->version]);
    }

    public function saveValues(
        Request $request, Company $company, string $asset, CompanyAccess $access, AssetAuditWriter $audit
    ): JsonResponse {
        $access->canWrite($request,$company);
        $data=$request->validate([
            'version'=>['required','integer','min:1'],
            'values'=>['required','array','max:100'],
        ]);
        $result=DB::transaction(function() use($company,$asset,$request,$data,$audit){
            $model=$company->assets()->whereKey($asset)->lockForUpdate()->firstOrFail();
            abort_unless($model->version===$data['version'],409,'Asset changed; reload');
            abort_unless($model->status==='active',409,'Asset is not editable');
            $definitions=DB::table('asset_field_definitions')->where('company_id',$company->id)
                ->where('category_id',$model->category_id)->get()->keyBy('id');
            $submitted=$data['values'];
            foreach($submitted as $fieldId=>$value){
                $definition=$definitions->get($fieldId);
                if(!$definition||!$definition->is_active){
                    throw ValidationException::withMessages(['values'=>['Unsupported field for this category']]);
                }
                $this->validateValue($fieldId,$value,$definition);
            }
            foreach($definitions as $definition){
                if(!$definition->is_active||!$definition->required)continue;
                $value=$submitted[$definition->id]??null;
                if($value===null||$value===''){
                    throw ValidationException::withMessages(['values'=>['Required field: '.$definition->label_ru]]);
                }
            }
            $old=DB::table('asset_field_values')->where('company_id',$company->id)
                ->where('asset_id',$model->id)->get(['field_id','value']);
            $before=[];
            foreach($old as $row)$before[$row->field_id]=json_decode($row->value,true);
            $after=$before;
            foreach($submitted as $fieldId=>$value){
                $after[$fieldId]=$value;
                DB::table('asset_field_values')->updateOrInsert(
                    ['company_id'=>$company->id,'asset_id'=>$model->id,'field_id'=>$fieldId],
                    ['value'=>json_encode($value),'updated_at'=>now(),'created_at'=>now()]
                );
            }
            if($before!==$after){
                $model->version++;
                $model->save();
                $audit->record($model,$request->user(),'attributes_updated',
                    ['attributes'=>$before],['attributes'=>$after]);
            }
            return ['data'=>$after,'asset_version'=>$model->version];
        },3);
        return response()->json($result);
    }

    private function validateValue(string $id,mixed $value,object $definition): void
    {
        if($value===null||$value==='')return;
        $valid=match($definition->field_type){
            'text'=>is_string($value)&&mb_strlen($value)<=1000,
            'number'=>(is_int($value)||is_float($value))&&is_finite((float) $value),
            'boolean'=>is_bool($value),
            'date'=>is_string($value)&&preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$value)
                &&checkdate((int)substr($value,5,2),(int)substr($value,8,2),(int)substr($value,0,4)),
            'select'=>is_string($value)&&in_array($value,json_decode($definition->options??'[]',true),true),
            default=>false,
        };
        if(!$valid)throw ValidationException::withMessages(['values.'.$id=>['Invalid field value']]);
    }
}
