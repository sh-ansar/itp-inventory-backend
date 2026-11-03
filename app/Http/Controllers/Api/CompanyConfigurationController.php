<?php

namespace App\Http\Controllers\Api;

use App\Domain\Inventory\CompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CompanyConfigurationController extends Controller
{
    private const KINDS = ['stocktake','movement','disposal','receipt','issue'];
    private const LOCALES = ['ru','en','ka'];
    private const PLACEHOLDERS = [
        'company_name','legal_name','asset_number','asset_name','date',
        'document_number','responsible_person','facility_name',
    ];

    public function settings(Request $request, Company $company, CompanyAccess $access): JsonResponse
    {
        $access->member($request,$company);
        $item=DB::table('inventory_company_settings')->where('company_id',$company->id)->first();
        return response()->json(['data'=>$item??[
            'company_id'=>$company->id,'legal_name'=>$company->name,
            'country_code'=>'KZ','timezone'=>'Asia/Almaty','currency'=>'KZT',
            'asset_number_prefix'=>'INV','document_number_prefix'=>'DOC',
            'default_locale'=>'ru','version'=>0,
        ]]);
    }

    public function saveSettings(Request $request, Company $company, CompanyAccess $access): JsonResponse
    {
        $access->canArchive($request,$company);
        $data=$request->validate([
            'version'=>['required','integer','min:0'],
            'legal_name'=>['required','string','min:2','max:255'],
            'country_code'=>['required','string','size:2','regex:/\A[A-Z]{2}\z/D'],
            'timezone'=>['required','string',Rule::in(\DateTimeZone::listIdentifiers())],
            'currency'=>['required','string','size:3','regex:/\A[A-Z]{3}\z/D'],
            'asset_number_prefix'=>['required','string','max:30','regex:/\A[A-Z0-9_-]+\z/D'],
            'document_number_prefix'=>['required','string','max:30','regex:/\A[A-Z0-9_-]+\z/D'],
            'default_locale'=>['required',Rule::in(self::LOCALES)],
        ]);
        $value=DB::transaction(function() use($company,$data){
            $existing=DB::table('inventory_company_settings')->where('company_id',$company->id)
                ->lockForUpdate()->first();
            abort_unless(($existing->version??0)===$data['version'],409,'Settings changed; reload');
            DB::table('inventory_company_settings')->updateOrInsert(
                ['company_id'=>$company->id],[
                    ...collect($data)->except('version')->all(),
                    'version'=>$data['version']+1,
                    'updated_at'=>now(),
                    'created_at'=>$existing?->created_at??now(),
                ]);
            return DB::table('inventory_company_settings')->where('company_id',$company->id)->first();
        },3);
        return response()->json(['data'=>$value]);
    }

    public function templates(Request $request, Company $company, CompanyAccess $access): JsonResponse
    {
        $access->member($request,$company);
        $data=$request->validate([
            'kind'=>['nullable',Rule::in(self::KINDS)],
            'locale'=>['nullable',Rule::in(self::LOCALES)],
        ]);
        $q=DB::table('inventory_document_templates')->where('company_id',$company->id);
        if(!empty($data['kind']))$q->where('kind',$data['kind']);
        if(!empty($data['locale']))$q->where('locale',$data['locale']);
        return response()->json(['data'=>$q->orderBy('kind')->orderBy('locale')
            ->orderByDesc('revision')->limit(200)->get()]);
    }

    public function createTemplate(Request $request, Company $company, CompanyAccess $access): JsonResponse
    {
        $access->canArchive($request,$company);
        $data=$request->validate([
            'kind'=>['required',Rule::in(self::KINDS)],
            'locale'=>['required',Rule::in(self::LOCALES)],
            'title'=>['required','string','min:2','max:200'],
            'body'=>['required','string','min:5','max:12000'],
        ]);
        $this->validatePlaceholders($data['body']);
        $template=DB::transaction(function() use($data,$company,$request){
            // A company row is the stable lock for serialized revision allocation.
            DB::table('companies')->where('id',$company->id)->lockForUpdate()->first();
            $revision=(int)DB::table('inventory_document_templates')->where('company_id',$company->id)
                ->where('kind',$data['kind'])->where('locale',$data['locale'])->max('revision')+1;
            $id=(string) Str::uuid();
            DB::table('inventory_document_templates')->insert([
                'id'=>$id,'company_id'=>$company->id,'kind'=>$data['kind'],'locale'=>$data['locale'],
                'revision'=>$revision,'title'=>trim($data['title']),'body'=>$data['body'],
                'status'=>'draft','created_by'=>$request->user()->id,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            return DB::table('inventory_document_templates')->where('id',$id)->first();
        },3);
        return response()->json(['data'=>$template],201);
    }

    public function publish(Request $request, Company $company, string $template, CompanyAccess $access): JsonResponse
    {
        $access->canArchive($request,$company);
        $data=$request->validate(['revision'=>['required','integer','min:1']]);
        $value=DB::transaction(function() use($company,$template,$data,$request){
            DB::table('companies')->where('id',$company->id)->lockForUpdate()->first();
            $row=DB::table('inventory_document_templates')->where('company_id',$company->id)
                ->where('id',$template)->first();
            abort_unless($row,404);
            abort_unless($row->revision===$data['revision'],409,'Template revision changed');
            abort_unless($row->status==='draft',409,'Only draft templates can be published');
            DB::table('inventory_document_templates')->where('company_id',$company->id)
                ->where('kind',$row->kind)->where('locale',$row->locale)->where('status','published')
                ->update(['status'=>'archived','updated_at'=>now()]);
            DB::table('inventory_document_templates')->where('id',$row->id)->update([
                'status'=>'published','published_at'=>now(),
                'published_by'=>$request->user()->id,'updated_at'=>now(),
            ]);
            return DB::table('inventory_document_templates')->where('id',$row->id)->first();
        },3);
        return response()->json(['data'=>$value]);
    }

    public function preview(Request $request, Company $company, string $template, CompanyAccess $access): JsonResponse
    {
        $access->member($request,$company);
        $row=DB::table('inventory_document_templates')->where('company_id',$company->id)
            ->where('id',$template)->first();
        abort_unless($row,404);
        $data=$request->validate([
            'asset_number'=>['nullable','string','max:100'],
            'asset_name'=>['nullable','string','max:255'],
            'document_number'=>['nullable','string','max:100'],
            'responsible_person'=>['nullable','string','max:255'],
            'facility_name'=>['nullable','string','max:255'],
        ]);
        $legal=DB::table('inventory_company_settings')->where('company_id',$company->id)
            ->value('legal_name')??$company->name;
        $replacements=[
            'company_name'=>$company->name,'legal_name'=>$legal,
            'date'=>now()->toDateString(),
        ]+$data;
        $output=preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/',fn($m)=>$replacements[$m[1]]??'', $row->body);
        return response()->json(['data'=>[
            'title'=>$row->title,'body'=>$output,'revision'=>$row->revision,
            'status'=>$row->status,'locale'=>$row->locale,
        ]]);
    }

    private function validatePlaceholders(string $body): void
    {
        if(str_contains($body,'<')||str_contains($body,'>')){
            throw ValidationException::withMessages(['body'=>['Templates use plain text, not HTML']]);
        }
        preg_match_all('/\{\{\s*([^{}]+)\s*\}\}/',$body,$matches);
        foreach($matches[1] as $variable){
            if(!in_array(trim($variable),self::PLACEHOLDERS,true)){
                throw ValidationException::withMessages(['body'=>['Unknown template placeholder']]);
            }
        }
    }
}
