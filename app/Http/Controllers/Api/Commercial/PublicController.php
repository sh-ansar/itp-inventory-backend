<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Http\Controllers\Controller;
use App\Services\Commercial\Access;
use App\Services\Commercial\Catalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

final class PublicController extends Controller
{
    public function plans(Catalog $catalog): JsonResponse
    {
        return response()->json(['data' => $catalog->list(true)]);
    }

    private function language(Request $request): string
    {
        $locale = $request->input('locale','ru');
        if ($locale === 'ka') $locale = 'ge';
        abort_unless(in_array($locale,['ru','en','ge'],true),422);
        return $locale;
    }

    private function currentLegal(Request $request): array
    {
        $locale = $this->language($request);
        $jurisdiction = $request->input('jurisdiction','KZ');
        abort_unless(in_array($jurisdiction,['KZ','GE'],true),422);
        $query = DB::table('inventory_legal_versions')
            ->where('locale',$locale)->where('jurisdiction',$jurisdiction)
            ->where('status','published')->where('effective_at','<=',now());
        if (!(clone $query)->exists() && $locale !== 'ru' && $jurisdiction === 'KZ') {
            $query = DB::table('inventory_legal_versions')
                ->where('locale','ru')->where('jurisdiction',$jurisdiction)
                ->where('status','published')->where('effective_at','<=',now());
        }
        $found = [];
        foreach ($query->orderByDesc('version')->get() as $row)
            if (!isset($found[$row->type])) $found[$row->type] = $row;
        return array_values($found);
    }

    public function legal(Request $request): JsonResponse
    {
        return response()->json(['data'=>array_map(fn($v) => [
            'id'=>$v->id,'type'=>$v->type,'locale'=>$v->locale,
            'jurisdiction'=>$v->jurisdiction,'title'=>$v->title,
            'version'=>$v->version,'sha256'=>$v->sha256,
            'acceptance'=>$v->acceptance,'required'=>$v->required,
            'effective_at'=>$v->effective_at,
        ], $this->currentLegal($request))]);
    }

    public function legalDocument(int $version): JsonResponse
    {
        $doc = DB::table('inventory_legal_versions')->where('id',$version)
            ->where('status','published')->where('effective_at','<=',now())->first();
        abort_unless($doc,404);
        return response()->json(['data'=>[
            'id'=>$doc->id,'type'=>$doc->type,'locale'=>$doc->locale,
            'jurisdiction'=>$doc->jurisdiction,'title'=>$doc->title,
            'body'=>$doc->body,'version'=>$doc->version,
            'sha256'=>$doc->sha256,'required'=>$doc->required,
            'acceptance'=>$doc->acceptance,'effective_at'=>$doc->effective_at,
        ]]);
    }

    public function subscription(Request $request): JsonResponse
    {
        $company = $request->query('company_id');
        $request->validate(['company_id'=>'required|uuid']);
        Access::company($request,$company);
        $row = DB::table('inventory_company_subscriptions')->where('company_id',$company)->first();
        if ($row) $row->snapshot = json_decode($row->snapshot,true);
        return response()->json(['data'=>$row]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $company = $request->query('company_id');
        $request->validate(['company_id'=>'required|uuid']);
        Access::company($request,$company);
        return response()->json(DB::table('inventory_invoices')
            ->where('company_id',$company)->orderByDesc('id')->paginate(20));
    }

    public function invoicePdf(Request $request, int $invoice)
    {
        $row = DB::table('inventory_invoices')->where('id',$invoice)->first();
        abort_unless($row,404);
        Access::company($request,$row->company_id);
        return app(\App\Services\Commercial\Printable::class)->invoice($row);
    }

    public function proof(Request $request, int $invoice): JsonResponse
    {
        $row = DB::table('inventory_invoices')->where('id',$invoice)->first();
        abort_unless($row,404);
        Access::company($request,$row->company_id,true);
        abort_unless(in_array($row->status,['issued','awaiting_payment','overdue'],true),409);
        $validated = $request->validate(['file'=>'required|file|mimes:pdf,png,jpg,jpeg|max:10240']);
        $file = $validated['file'];
        $path = $file->store('inventory-proofs/'.$row->company_id,'local');
        abort_unless($path,500);
        $id = DB::table('inventory_payment_proofs')->insertGetId([
            'invoice_id'=>$invoice,'uploaded_by'=>$request->user()->id,
            'storage_path'=>$path,'filename'=>$file->getClientOriginalName(),
            'mime'=>$file->getMimeType(),'size_bytes'=>$file->getSize(),
            'status'=>'pending','created_at'=>now(),'updated_at'=>now(),
        ]);
        Access::audit($request->user()->id,$row->company_id,'invoice.proof','invoice:'.$invoice,['proof_id'=>$id]);
        return response()->json(['id'=>$id,'status'=>'pending'],201);
    }

    public function accept(Request $request): JsonResponse
    {
        $input = $request->validate([
            'locale'=>['required',Rule::in(['ru','en','ge','ka'])],
            'jurisdiction'=>['required',Rule::in(['KZ','GE'])],
            'choices'=>'required|array',
            'choices.*'=>'boolean',
        ]);
        $request->merge(['locale'=>$input['locale'],'jurisdiction'=>$input['jurisdiction']]);
        $documents = $this->currentLegal($request);
        DB::transaction(function() use ($documents,$input,$request) {
            foreach ($documents as $doc) {
                $selected = ($input['choices'][$doc->type] ?? false) === true;
                abort_if($doc->required && !$selected,422,'Required legal document was not accepted.');
                if (!$selected) continue;
                DB::table('inventory_legal_consents')->insertOrIgnore([
                    'user_id'=>$request->user()->id,'legal_version_id'=>$doc->id,
                    'sha256'=>$doc->sha256,'action'=>$doc->acceptance,'source'=>'inventory',
                    'locale'=>$doc->locale,'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
        });
        return response()->json(['ok'=>true]);
    }

    public function consents(Request $request): JsonResponse
    {
        return response()->json(DB::table('inventory_legal_consents as c')
            ->join('inventory_legal_versions as v','v.id','=','c.legal_version_id')
            ->where('c.user_id',$request->user()->id)
            ->orderByDesc('c.id')
            ->select('c.*','v.type','v.title','v.version')->paginate(25));
    }
}
