<?php

namespace App\Http\Controllers\Api;

use App\Domain\Inventory\CompanyAccess;
use App\Domain\Inventory\CompanyRole;
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

    public function store(Request $request,Company $company,CompanyAccess $access): JsonResponse
    {
        $access->canArchive($request,$company);
        $data=$request->validate([
            'email'=>['required','email','max:255'],
            'role_code'=>['required',Rule::in(CompanyRole::values())],
            'reason'=>['required','string','min:5','max:1000'],
        ]);
        return DB::transaction(function () use ($request,$company,$data) {
            $email=mb_strtolower(trim($data['email']));
            $person=User::query()->whereRaw('LOWER(email) = ?',[$email])->where('is_active',true)->first();
            if (!$person) throw ValidationException::withMessages(['email'=>['No active Inventory account found']]);
            abort_if(CompanyMembership::query()->where('company_id',$company->id)
                ->where('user_id',$person->id)->exists(),409,'User already belongs to this company');
            $person->companyMemberships()->create([
                'company_id'=>$company->id,'role_code'=>$data['role_code'],
            ]);
            DB::table('company_membership_events')->insert([
                'id'=>(string) Str::uuid(),'company_id'=>$company->id,
                'target_user_id'=>$person->id,'actor_id'=>$request->user()->id,
                'actor_name'=>$request->user()->name,'event_type'=>'member_added',
                'after_role'=>$data['role_code'],'reason'=>$data['reason'],
                'created_at'=>now(),
            ]);
            return response()->json(['ok'=>true,'user_id'=>$person->id],201);
        },3);
    }

    public function destroy(Request $request,Company $company,int $user,CompanyAccess $access): JsonResponse
    {
        $access->canArchive($request,$company);
        $data=$request->validate(['reason'=>['required','string','min:5','max:1000']]);
        return DB::transaction(function () use ($company,$request,$user,$data) {
            $members=CompanyMembership::query()->where('company_id',$company->id)
                ->orderBy('id')->lockForUpdate()->get();
            $member=$members->firstWhere('user_id',$user);
            abort_unless($member,404);
            abort_if($user===$request->user()->id,409,'Cannot revoke your own access');
            abort_if($member->role_code===CompanyRole::Admin->value &&
                $members->where('role_code',CompanyRole::Admin->value)->count()<=1,409,
                'At least one company administrator must remain');
            $previous=$member->role_code;
            $member->delete();
            DB::table('company_membership_events')->insert([
                'id'=>(string) Str::uuid(),'company_id'=>$company->id,
                'target_user_id'=>$user,'actor_id'=>$request->user()->id,
                'actor_name'=>$request->user()->name,'event_type'=>'member_removed',
                'before_role'=>$previous,'reason'=>$data['reason'],'created_at'=>now(),
            ]);
            return response()->json(['ok'=>true]);
        },3);
    }

    public function update(Request $request,Company $company,int $user,CompanyAccess $access): JsonResponse
    {
        $access->canArchive($request,$company);
        $data=$request->validate([
            'role_code'=>['required',Rule::in(CompanyRole::values())],
            'reason'=>['required','string','min:5','max:1000'],
        ]);
        return DB::transaction(function () use ($request,$company,$user,$data) {
            $members=CompanyMembership::query()->where('company_id',$company->id)
                ->orderBy('id')->lockForUpdate()->get();
            $member=$members->firstWhere('user_id',$user);
            abort_unless($member,404);
            $before=$member->role_code;
            if ($before===CompanyRole::Admin->value && $data['role_code']!==CompanyRole::Admin->value
                && $members->where('role_code',CompanyRole::Admin->value)->count()<=1) {
                throw ValidationException::withMessages(['role_code'=>['At least one company administrator must remain']]);
            }
            if ($user===$request->user()->id && $before===CompanyRole::Admin->value
                && $data['role_code']!==CompanyRole::Admin->value) {
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
