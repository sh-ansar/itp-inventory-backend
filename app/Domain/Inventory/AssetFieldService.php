<?php

namespace App\Domain\Inventory;

use App\Models\Asset;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AssetFieldService
{
    public function validate(Company $company, ?string $categoryId, array $values): void
    {
        if(count($values)>100)throw ValidationException::withMessages(['attributes'=>['Too many fields']]);
        $defs=DB::table('asset_field_definitions')->where('company_id',$company->id)
            ->where('category_id',$categoryId)->where('is_active',true)->get()->keyBy('id');
        foreach($values as $id=>$value){
            $field=$defs->get($id);
            if(!$field)throw ValidationException::withMessages(['attributes'=>['Unsupported field for this asset category']]);
            $ok=$value===null||$value===''||match($field->field_type){
                'text'=>is_string($value)&&mb_strlen($value)<=1000,
                'number'=>(is_int($value)||is_float($value))&&is_finite((float)$value),
                'boolean'=>is_bool($value),
                'date'=>is_string($value)&&preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$value)
                    &&checkdate((int)substr($value,5,2),(int)substr($value,8,2),(int)substr($value,0,4)),
                'select'=>is_string($value)&&in_array($value,json_decode($field->options??'[]',true),true),
                default=>false,
            };
            if(!$ok)throw ValidationException::withMessages(['attributes.'.$id=>['Invalid value']]);
        }
        foreach($defs as $field){
            if($field->required&&(!array_key_exists($field->id,$values)||$values[$field->id]===null||$values[$field->id]==='')){
                throw ValidationException::withMessages(['attributes.'.$field->id=>['Required attribute: '.$field->label_ru]]);
            }
        }
    }

    public function values(Asset $asset): array
    {
        $rows=DB::table('asset_field_values')->where('company_id',$asset->company_id)
            ->where('asset_id',$asset->id)->get(['field_id','value']);
        $result=[];
        foreach($rows as $row)$result[$row->field_id]=json_decode($row->value,true);
        return $result;
    }

    public function persist(Asset $asset, array $values): void
    {
        foreach($values as $fieldId=>$value){
            DB::table('asset_field_values')->updateOrInsert(
                ['company_id'=>$asset->company_id,'asset_id'=>$asset->id,'field_id'=>$fieldId],
                ['value'=>json_encode($value),'updated_at'=>now()]
            );
        }
    }
}
