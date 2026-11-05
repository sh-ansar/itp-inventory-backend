<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Http\Controllers\Controller;
use App\Services\Commercial\Access;
use App\Services\Commercial\Catalog;
use App\Services\Commercial\Printable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

final class AdminController extends Controller
{
    private function paginate(Request $request, $query, int $max = 50)
    {
        return $query->paginate(min($max,max(1,(int)$request->input('per_page',20))));
    }

    public function overview(Request $request): JsonResponse
    {
        $permissions = Access::permissions($request);
        abort_unless($permissions,403);
        return response()->json([
            'permissions'=>$permissions,
            'modules'=>Catalog::MODULES,
            'flags'=>[
                'invoice_issuance_enabled'=>config('inventory.invoice_issuance_enabled'),
                'legal_publication_enabled'=>config('inventory.legal_publication_enabled'),
                'public_registration_enabled'=>config('inventory.public_registration_enabled'),
            ],
            'statistics'=>[
                'companies'=>DB::table('companies')->count(),
                'users'=>DB::table('users')->count(),
                'published_plans'=>count(app(Catalog::class)->list(true)),
            ],
        ]);
    }

    public function organizations(Request $request): JsonResponse
    {
        Access::need($request,'companies.read');
        $query = DB::table('companies as c')
            ->select('c.id','c.name','c.created_at');
        $query->selectSub(function($q){
            $q->from('company_memberships as m')->join('users as u','u.id','=','m.user_id')
              ->whereColumn('m.company_id','c.id')->orderBy('m.id')->limit(1)->select('u.email');
        },'email');
        $query->selectSub(function($q){
            $q->from('company_memberships as m')->join('users as u','u.id','=','m.user_id')
              ->whereColumn('m.company_id','c.id')->orderBy('m.id')->limit(1)->select('u.email_verified_at');
        },'email_verified_at');
        $query->selectSub(fn($q) => $q->from('company_memberships as m')
            ->whereColumn('m.company_id','c.id')->selectRaw('count(*)'),'users_count');
        $query->selectSub(fn($q) => $q->from('assets as a')
            ->whereColumn('a.company_id','c.id')->selectRaw('count(*)'),'assets_count');
        $search = trim((string)$request->query('search',''));
        if ($search !== '') $query->where(function($q) use ($search) {
            $q->where('c.name','like','%'.$search.'%')
              ->orWhereExists(fn($s) => $s->selectRaw('1')->from('company_memberships as m')
                  ->join('users as u','u.id','=','m.user_id')
                  ->whereColumn('m.company_id','c.id')->where('u.email','like','%'.$search.'%'));
        });
        $sort = $request->query('sort','created_at');
        $query->orderBy(in_array($sort,['name','created_at'],true)?'c.'.$sort:'c.created_at',
            $sort==='name'?'asc':'desc');
        $paginator = $this->paginate($request,$query,100);
        $ids = $paginator->getCollection()->pluck('id')->all();
        $subscriptions = DB::table('inventory_company_subscriptions')
            ->whereIn('company_id',$ids)->get()->keyBy('company_id');
        $paginator->getCollection()->transform(function($row)use($subscriptions) {
            $sub=$subscriptions->get($row->id);
            $snapshot=$sub?json_decode($sub->snapshot,true):[];
            $row->plan_code=$snapshot['code']??null;
            $row->subscription_status=$sub->status??null;
            $row->companies_count=1;
            $row->used_bytes=null; // Unknown is not zero usage.
            $row->is_verified=($row->email_verified_at!==null);
            unset($row->email_verified_at);
            return $row;
        });
        return response()->json($paginator);
    }

    public function organization(Request $request,string $company): JsonResponse
    {
        Access::need($request,'companies.read');
        $org=DB::table('companies')->where('id',$company)->first();
        abort_unless($org,404);
        $sub=DB::table('inventory_company_subscriptions')->where('company_id',$company)->first();
        if($sub)$sub->snapshot=json_decode($sub->snapshot,true);
        $profile=DB::table('inventory_billing_profiles')->where('company_id',$company)->first();
        return response()->json([
            'organization'=>$org,
            'subscription'=>$sub,
            'companies'=>[['id'=>$company,'name'=>$org->name,'used_bytes'=>null,'technical_limit_bytes'=>$sub->snapshot['terms']['limits']['storage_bytes']??null]],
            'billing_profile'=>$profile?json_decode($profile->details,true):null,
            'audit'=>DB::table('inventory_commercial_audit')->where('company_id',$company)
                ->orderByDesc('id')->limit(100)->get(),
        ]);
    }

    public function users(Request $request,string $company): JsonResponse
    {
        Access::need($request,'companies.read');
        abort_unless(DB::table('companies')->where('id',$company)->exists(),404);
        return response()->json($this->paginate($request,
            DB::table('company_memberships as m')
                ->join('users as u','u.id','=','m.user_id')
                ->where('m.company_id',$company)->select('u.id','u.email','u.name',
                    'u.email_verified_at','m.role_code','u.is_active')->orderBy('u.name')));
    }

    public function resend(Request $request,string $company,int $user): JsonResponse
    {
        Access::need($request,'users.write');
        abort_unless(DB::table('company_memberships')->where('company_id',$company)->where('user_id',$user)->exists(),404);
        $account=DB::table('users')->where('id',$user)->first();
        abort_unless($account,404);
        // Existing Inventory users are provisioned by admins; do not silently "verify" them.
        if ($account->email_verified_at) return response()->json(['ok'=>true]);
        abort(409,'Account verification must be initiated by the user; no token is stored for existing accounts.');
    }

    public function plans(Request $request,Catalog $catalog): JsonResponse
    {
        Access::need($request,'plans.read');
        return response()->json(['data'=>$catalog->list(false)]);
    }

    public function savePlan(Request $request,Catalog $catalog): JsonResponse
    {
        Access::need($request,'plans.write');
        return response()->json(['data'=>$catalog->draft($request->all(),$request->user()->id)],201);
    }

    public function planState(Request $request,Catalog $catalog,int $version): JsonResponse
    {
        Access::need($request,'plans.write');
        $data=$request->validate(['status'=>['required',Rule::in(['published','archived'])]]);
        return response()->json(['data'=>$catalog->transition($version,$data['status'],$request->user()->id)]);
    }

    public function assign(Request $request,Catalog $catalog,string $company): JsonResponse
    {
        Access::need($request,'subscriptions.write');
        $input=$request->validate([
            'plan_version_id'=>'required|integer|exists:inventory_plan_versions,id',
            'ends_at'=>'nullable|date|after:now',
        ]);
        $catalog->assign($company,(int)$input['plan_version_id'],$input['ends_at']??null,$request->user()->id);
        return response()->json(['ok'=>true]);
    }

    private function profile(?string $company): array
    {
        $key=$company ? 'company:'.$company : 'supplier';
        $row=DB::table('inventory_billing_profiles')->where('profile_key',$key)->first();
        return $row ? json_decode($row->details,true) : [];
    }

    private function saveProfile(?string $company,array $details): array
    {
        $key=$company ? 'company:'.$company : 'supplier';
        DB::table('inventory_billing_profiles')->upsert([[
            'profile_key'=>$key,'company_id'=>$company,
            'details'=>json_encode($details,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
            'created_at'=>now(),'updated_at'=>now(),
        ]],['profile_key'],['details','updated_at']);
        return $details;
    }

    private function validateProfile(Request $request): array
    {
        return $request->validate([
            'name'=>'required|string|max:250','bin'=>'nullable|string|max:30',
            'address'=>'nullable|string|max:500','bank'=>'nullable|string|max:250',
            'iban'=>'nullable|string|max:64','bic'=>'nullable|string|max:64',
            'email'=>'nullable|email|max:255','phone'=>'nullable|string|max:40',
        ]);
    }

    public function billingProfile(Request $request): JsonResponse
    {
        Access::need($request,'billing.read');
        return response()->json(['data'=>$this->profile(null)]);
    }

    public function saveSupplier(Request $request): JsonResponse
    {
        Access::need($request,'billing.write');
        $details=$this->validateProfile($request);
        $details['assets']=$this->profile(null)['assets']??[];
        $this->saveProfile(null,$details);
        Access::audit($request->user()->id,null,'billing.supplier','profile:supplier');
        return response()->json(['data'=>$details]);
    }

    public function saveBuyer(Request $request,string $company): JsonResponse
    {
        Access::need($request,'billing.write');
        abort_unless(DB::table('companies')->where('id',$company)->exists(),404);
        $details=$this->saveProfile($company,$this->validateProfile($request));
        Access::audit($request->user()->id,$company,'billing.buyer','profile:buyer');
        return response()->json(['data'=>$details]);
    }

    public function billingAsset(Request $request,string $kind): JsonResponse
    {
        Access::need($request,'billing.write');
        abort_unless(in_array($kind,['logo','signature','stamp'],true),422);
        $input=$request->validate(['asset'=>'required|image|mimes:png,jpg,jpeg|max:2048']);
        $path=$input['asset']->store('inventory-billing/'.$kind,'local');
        abort_unless($path,500);
        $profile=$this->profile(null);$profile['assets'][$kind]=$path;
        $this->saveProfile(null,$profile);
        Access::audit($request->user()->id,null,'billing.asset','asset:'.$kind);
        return response()->json(['ok'=>true]);
    }

    public function invoices(Request $request): JsonResponse
    {
        Access::need($request,'billing.read');
        return response()->json($this->paginate($request,
            DB::table('inventory_invoices')->orderByDesc('id'),100));
    }

    public function createInvoice(Request $request): JsonResponse
    {
        Access::need($request,'billing.write');
        $input=$request->validate([
            'company_id'=>'required|uuid|exists:companies,id',
            'period_from'=>'required|date',
            'period_to'=>'required|date|after_or_equal:period_from',
            'due_date'=>'required|date',
            'basis'=>'required|string|max:300',
            'items'=>'required|array|min:1|max:100',
            'items.*.description'=>'required|string|max:500',
            'items.*.quantity'=>'required|integer|min:1|max:1000000',
            'items.*.unit_minor'=>'required|integer|min:0|max:1000000000',
        ]);
        $total=0;
        foreach ($input['items'] as $item) {
            $total += $item['quantity']*$item['unit_minor'];
            abort_if($total>100000000000,422,'Invoice total exceeds allowed maximum.');
        }
        $supplier=$this->profile(null);$buyer=$this->profile($input['company_id']);
        $id=DB::table('inventory_invoices')->insertGetId([
            'company_id'=>$input['company_id'],'status'=>'draft','currency'=>'KZT',
            'total_minor'=>$total,'period_from'=>$input['period_from'],
            'period_to'=>$input['period_to'],'due_date'=>$input['due_date'],
            'basis'=>$input['basis'],
            'items'=>json_encode($input['items'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
            'supplier_snapshot'=>json_encode($supplier,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
            'buyer_snapshot'=>json_encode($buyer,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
            'created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now(),
        ]);
        Access::audit($request->user()->id,$input['company_id'],'invoice.draft','invoice:'.$id);
        return response()->json(['data'=>DB::table('inventory_invoices')->find($id)],201);
    }

    public function invoiceState(Request $request,int $invoice): JsonResponse
    {
        Access::need($request,'billing.write');
        $input=$request->validate(['status'=>['required',Rule::in(['issued','cancelled'])]]);
        $row=DB::table('inventory_invoices')->where('id',$invoice)->first();
        abort_unless($row,404);
        if($input['status']==='issued') {
            abort_unless(config('inventory.invoice_issuance_enabled'),409,'Invoice issuance disabled.');
            abort_unless($row->status==='draft',409);
            abort_unless(!empty(json_decode($row->buyer_snapshot,true)['name'])
                && !empty(json_decode($row->supplier_snapshot,true)['name']),409,'Billing profiles required.');
            $updates=['status'=>'issued','number'=>'INV-'.now()->year.'-'.str_pad((string)$invoice,6,'0',STR_PAD_LEFT)];
        } else {
            abort_unless(in_array($row->status,['issued','awaiting_payment','overdue'],true),409);
            $updates=['status'=>'cancelled'];
        }
        DB::table('inventory_invoices')->where('id',$invoice)->update([...$updates,'updated_at'=>now()]);
        Access::audit($request->user()->id,$row->company_id,'invoice.'.$input['status'],'invoice:'.$invoice);
        return response()->json(['ok'=>true]);
    }

    public function invoicePdf(Request $request,int $invoice)
    {
        Access::need($request,'billing.read');
        $row=DB::table('inventory_invoices')->where('id',$invoice)->first();
        abort_unless($row,404);
        return app(Printable::class)->invoice($row);
    }

    public function proofs(Request $request,int $invoice): JsonResponse
    {
        Access::need($request,'billing.read');
        abort_unless(DB::table('inventory_invoices')->where('id',$invoice)->exists(),404);
        return response()->json(['data'=>DB::table('inventory_payment_proofs')
            ->where('invoice_id',$invoice)->orderByDesc('id')->get()]);
    }

    public function proofFile(Request $request,int $proof)
    {
        Access::need($request,'billing.read');
        $row=DB::table('inventory_payment_proofs')->where('id',$proof)->first();
        abort_unless($row,404);
        abort_unless(Storage::disk('local')->exists($row->storage_path),404);
        return Storage::disk('local')->download($row->storage_path,$row->filename,[
            'Content-Type'=>$row->mime,'X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function reviewProof(Request $request,int $proof): JsonResponse
    {
        Access::need($request,'billing.write');
        $input=$request->validate([
            'status'=>['required',Rule::in(['accepted','rejected'])],
            'review_note'=>'nullable|string|max:2000',
        ]);
        // Lock both rows in the same transaction so two reviewers cannot settle twice.
        DB::transaction(function()use($proof,$request,$input) {
            $row=DB::table('inventory_payment_proofs')->where('id',$proof)->lockForUpdate()->first();
            abort_unless($row,404);
            abort_unless($row->status==='pending',409);
            $invoice=DB::table('inventory_invoices')->where('id',$row->invoice_id)->lockForUpdate()->first();
            abort_unless($invoice && in_array($invoice->status,['issued','awaiting_payment','overdue'],true),409);
            $updated=DB::table('inventory_payment_proofs')->where('id',$row->id)
                ->where('status','pending')->update([
                'status'=>$input['status'],'review_note'=>$input['review_note']??null,
                'reviewed_by'=>$request->user()->id,'updated_at'=>now(),
            ]);
            abort_unless($updated===1,409);
            if($input['status']==='accepted') DB::table('inventory_invoices')->where('id',$invoice->id)
                ->update(['status'=>'paid','updated_at'=>now()]);
            Access::audit($request->user()->id,$invoice->company_id,
                'invoice.proof.'.$input['status'],'proof:'.$row->id);
        },3);
        return response()->json(['ok'=>true]);
    }

    public function legalVersions(Request $request): JsonResponse
    {
        Access::need($request,'legal.read');
        return response()->json($this->paginate($request,
            DB::table('inventory_legal_versions')->orderByDesc('id'),100));
    }

    public function legalVersion(Request $request,int $version): JsonResponse
    {
        Access::need($request,'legal.read');
        $row=DB::table('inventory_legal_versions')->where('id',$version)->first();
        abort_unless($row,404);
        return response()->json(['data'=>$row]);
    }

    public function saveLegal(Request $request): JsonResponse
    {
        Access::need($request,'legal.write');
        $data=$request->validate([
            'type'=>['required',Rule::in(['offer','tariff_policy','acceptable_use','personal_data_consent','privacy'])],
            'locale'=>['required',Rule::in(['ru','en','ge'])],
            'jurisdiction'=>['required',Rule::in(['KZ','GE'])],
            'title'=>'required|string|max:250',
            'body'=>'required|string|min:50|max:250000',
            'acceptance'=>['required',Rule::in(['accepted','acknowledged','consented'])],
            'required'=>'required|boolean',
            'effective_at'=>'nullable|date',
        ]);
        // Persist timestamp in the database-native format, never ISO 8601 text.
        if (!empty($data['effective_at'])) {
            $data['effective_at']=\Carbon\Carbon::parse($data['effective_at'])->utc()->toDateTimeString();
        }
        $id=DB::transaction(function()use($request,$data) {
            $version=1+(int)DB::table('inventory_legal_versions')->where('type',$data['type'])
                ->where('locale',$data['locale'])->where('jurisdiction',$data['jurisdiction'])->max('version');
            $canonical=json_encode([...$data,'version'=>$version],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            $id=DB::table('inventory_legal_versions')->insertGetId([
                ...$data,'version'=>$version,'sha256'=>hash('sha256',$canonical),
                'status'=>'draft','created_by'=>$request->user()->id,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            Access::audit($request->user()->id,null,'legal.draft','legal:'.$id);
            return $id;
        },3);
        return response()->json(['data'=>DB::table('inventory_legal_versions')->find($id)],201);
    }

    public function legalState(Request $request,int $version): JsonResponse
    {
        $data=$request->validate([
            'status'=>['required',Rule::in(['approved','published'])],
            'approval_note'=>'nullable|string|max:3000',
        ]);
        Access::need($request,$data['status']==='approved'?'legal.approve':'legal.publish');
        $row=DB::table('inventory_legal_versions')->where('id',$version)->first();
        abort_unless($row,404);
        if($data['status']==='approved') {
            abort_unless($row->status==='draft',409);
            abort_unless(mb_strlen(trim($data['approval_note']??''))>=10,422,'Approval reference required.');
            $updates=['status'=>'approved','approved_by'=>$request->user()->id,
                'approval_note'=>trim($data['approval_note'])];
        } else {
            abort_unless(config('inventory.legal_publication_enabled'),409,'Publication disabled.');
            abort_unless($row->status==='approved'&&$row->approved_by&&$row->effective_at,409);
            $updates=['status'=>'published','published_at'=>now()];
        }
        DB::table('inventory_legal_versions')->where('id',$version)
            ->update([...$updates,'updated_at'=>now()]);
        Access::audit($request->user()->id,null,'legal.'.$data['status'],'legal:'.$version);
        return response()->json(['ok'=>true]);
    }

    public function legalPdf(Request $request,int $version)
    {
        Access::need($request,'legal.read');
        $row=DB::table('inventory_legal_versions')->where('id',$version)->first();
        abort_unless($row,404);
        return app(Printable::class)->legal($row);
    }

    public function consents(Request $request): JsonResponse
    {
        Access::need($request,'legal.read');
        $query=DB::table('inventory_legal_consents as c')
            ->join('users as u','u.id','=','c.user_id')
            ->join('inventory_legal_versions as v','v.id','=','c.legal_version_id')
            ->select('c.*','u.email','v.title','v.version','v.type')->orderByDesc('c.id');
        if($request->filled('search'))$query->where('u.email','like','%'.$request->query('search').'%');
        return response()->json($this->paginate($request,$query,100));
    }

    public function grants(Request $request): JsonResponse
    {
        Access::need($request,'platform.manage');
        return response()->json($this->paginate($request,
            DB::table('inventory_platform_grants as g')
                ->join('users as u','u.id','=','g.user_id')
                ->select('g.*','u.email')->orderByDesc('g.id'),100));
    }

    public function grant(Request $request): JsonResponse
    {
        Access::need($request,'platform.manage');
        $input=$request->validate([
            'user_id'=>'required|integer|exists:users,id',
            'permission'=>['required',Rule::in(Access::PERMISSIONS)],
        ]);
        DB::table('inventory_platform_grants')->insertOrIgnore([
            'user_id'=>$input['user_id'],'permission'=>$input['permission'],
            'created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now(),
        ]);
        Access::audit($request->user()->id,null,'access.granted','user:'.$input['user_id'],
            ['permission'=>$input['permission']]);
        return response()->json(['ok'=>true],201);
    }

    public function revoke(Request $request,int $grant): JsonResponse
    {
        Access::need($request,'platform.manage');
        $row=DB::table('inventory_platform_grants')->where('id',$grant)->first();
        abort_unless($row,404);
        if($row->permission==='platform.manage') {
            abort_unless(DB::table('inventory_platform_grants')->where('permission','platform.manage')->count()>1,
                409,'Cannot remove last platform administrator.');
        }
        DB::table('inventory_platform_grants')->where('id',$grant)->delete();
        Access::audit($request->user()->id,null,'access.revoked','user:'.$row->user_id,
            ['permission'=>$row->permission]);
        return response()->json(['ok'=>true]);
    }
}
