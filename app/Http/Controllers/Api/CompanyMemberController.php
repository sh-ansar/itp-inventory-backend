<?php

namespace App\Http\Controllers\Api;

use App\Domain\Inventory\CompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CompanyMemberController extends Controller
{
    public function index(Request $request,Company $company,CompanyAccess $access): JsonResponse
    {
        $access->canArchive($request,$company);
        $members=CompanyMembership::query()->where('company_id',$company->id)
            ->with('user:id,name,email,is_active')->get()
            ->map(fn($m)=>['user_id'=>$m->user_id,'name'=>$m->user->name,
                'email'=>$m->user->email,'active'=>$m->user->is_active,'role_code'=>$m->role_code]);
        return response()->json(['data'=>$members]);
    }
    public function update(Request $request,Company $company,int $user,CompanyAccess $access): JsonResponse
    {
        $access->canArchive($request,$company);
        $data=$request->validate([
            'role_code'=>['required',Rule::in(['viewer','inventory_manager','company_admin'])],
            'reason'=>['required','string','min:5','max:1000'],
        ]);
        return DB::transaction(function () use ($request,$company,$user,$data) {
            $members=CompanyMembership::query()->where('company_id',$company->id)
                ->orderBy('id')->lockForUpdate()->get();
            $member=$members->firstWhere('user_id',$user);
            abort_unless($member,404);
            $before=$member->role_code;
            if ($before==='company_admin' && $data['role_code']!=='company_admin'
                && $members->where('role_code','company_admin')->count()<=1) {
                throw ValidationException::withMessages(['role_code'=>['At least one company administrator must remain']]);
            }
            if ($user===$request->user()->id && $before==='company_admin'
                && $data['role_code']!=='company_admin') {
                throw ValidationException::withMessages(['role_code'=>['Do not demote your own administrator access']]);
            }
            if ($before===$data['role_code']) return response()->json(['ok'=>true,'changed'=>false]);
            $member->role_code=$data['role_code'];
            $member->save();
            DB::table('company_membership_events')->insert([
                'id'=>(string) Str::uuid(),'company_id'=>$company->id,'target_user_id'=>$user,
                'actor_id'=>$request->user()->id,'actor_name'=>$request->user()->name,
                'event_type'=>'role_changed','before_role'=>$before,'after_role'=>$data['role_code'],
                'reason'=>$data['reason'],'created_at'=>now(),
            ]);
            return response()->json(['ok'=>true,'changed'=>true]);
        },3);
    }
}
