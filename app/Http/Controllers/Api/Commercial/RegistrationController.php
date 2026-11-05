<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Commercial\Access;
use App\Services\Commercial\Catalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

final class RegistrationController extends Controller
{
    private function enabled(): void
    {
        abort_unless(config('inventory.public_registration_enabled'),503,'Public registration is not enabled.');
    }

    private function sendVerification(string $email, string $token, string $locale): void
    {
        $url = rtrim(config('inventory.public_url'),'/').'/verify-email?token='.$token;
        $messages = [
            'ru' => ['Подтверждение почты — ITP Inventory',
                "Подтвердите адрес электронной почты для регистрации в ITP Inventory:\n\n".$url."\n\nСсылка действует 24 часа."],
            'en' => ['ITP Inventory — email verification',
                "Verify your email address to register for ITP Inventory:\n\n".$url."\n\nThis link expires in 24 hours."],
            'ge' => ['ITP Inventory — ელფოსტის დადასტურება',
                "დაადასტურეთ ელფოსტა ITP Inventory-ში რეგისტრაციისთვის:\n\n".$url."\n\nბმული ძალაშია 24 საათით."],
        ];
        [$subject,$body] = $messages[$locale] ?? $messages['ru'];
        Mail::raw($body, fn($mail) => $mail->to($email)->subject($subject));
    }

    public function register(Request $request): JsonResponse
    {
        $this->enabled();
        $input = $request->validate([
            'name'=>'required|string|min:2|max:150',
            'email'=>'required|email|max:255',
            'company_name'=>'required|string|min:2|max:255',
            'password'=>['required','confirmed',Password::min(12)->mixedCase()->numbers()],
            'plan_code'=>'nullable|string|max:40',
            'locale'=>'required|in:ru,en,ge',
            'legal_choices'=>'nullable|array',
            'legal_choices.*'=>'boolean',
            'website'=>'nullable|string|max:100',
        ]);
        // Honeypot. Do not disclose existence of an address or human verification.
        if (!empty($input['website'])) return response()->json(['message'=>'Check your email.'],202);
        $email = Str::lower(trim($input['email']));
        if (User::where('email',$email)->exists()) return response()->json(['message'=>'Check your email.'],202);
        $plan = null;
        if (!empty($input['plan_code'])) {
            $plan = collect(app(Catalog::class)->list(true))->firstWhere('code',$input['plan_code']);
            abort_unless($plan,422,'Unknown public plan.');
        }
        // Capture only explicitly accepted current published versions. No implicit consent.
        $legalQuery = DB::table('inventory_legal_versions')
            ->where('locale',$input['locale'])->where('jurisdiction','KZ')
            ->where('status','published')->where('effective_at','<=',now());
        if (!(clone $legalQuery)->exists() && $input['locale']!=='ru') {
            $legalQuery = DB::table('inventory_legal_versions')->where('locale','ru')
                ->where('jurisdiction','KZ')->where('status','published')
                ->where('effective_at','<=',now());
        }
        $latestLegal = [];
        foreach ($legalQuery->orderByDesc('version')->get() as $doc)
            if (!isset($latestLegal[$doc->type])) $latestLegal[$doc->type] = $doc;
        $legalConsents=[];
        foreach ($latestLegal as $doc) {
            $chosen=($input['legal_choices'][$doc->type]??false)===true;
            abort_if($doc->required&&!$chosen,422,'Required agreement not accepted: '.$doc->type);
            if ($chosen) $legalConsents[]=[
                'legal_version_id'=>$doc->id,'sha256'=>$doc->sha256,
                'locale'=>$doc->locale,'action'=>$doc->acceptance,
            ];
        }
        $token = Str::random(64);
        $now = now();
        DB::table('inventory_registration_requests')->updateOrInsert(['email'=>$email],[
            'name'=>trim($input['name']),'password_hash'=>Hash::make($input['password']),
            'company_name'=>trim($input['company_name']),'plan_code'=>$plan?->code,
            'locale'=>$input['locale'],
            'legal_consents'=>json_encode($legalConsents,JSON_THROW_ON_ERROR),
            'token_hash'=>hash('sha256',$token),'expires_at'=>$now->copy()->addDay(),
            'created_at'=>$now,'updated_at'=>$now,
        ]);
        try {
            $this->sendVerification($email,$token,$input['locale']);
        } catch (\Throwable $exception) {
            DB::table('inventory_registration_requests')->where('email',$email)
                ->where('token_hash',hash('sha256',$token))->delete();
            report($exception);
            abort(503,'Verification email is temporarily unavailable.');
        }
        return response()->json(['message'=>'Check your email.'],202);
    }

    public function verify(Request $request): JsonResponse
    {
        $this->enabled();
        $input = $request->validate(['token'=>'required|string|size:64']);
        $hash = hash('sha256',$input['token']);
        $result = DB::transaction(function() use ($hash) {
            $pending = DB::table('inventory_registration_requests')
                ->where('token_hash',$hash)->lockForUpdate()->first();
            abort_unless($pending && now()->lessThan($pending->expires_at),422,'Invalid or expired verification link.');
            abort_if(User::where('email',$pending->email)->exists(),409);
            $user = User::create([
                'name'=>$pending->name,'email'=>$pending->email,
                'password'=>$pending->password_hash,'is_active'=>true,
            ]);
            $user->email_verified_at = now();
            $user->save();
            $company = Company::create(['id'=>(string)Str::uuid(),'name'=>$pending->company_name]);
            CompanyMembership::create(['company_id'=>$company->id,'user_id'=>$user->id,'role_code'=>'company_admin']);
            if ($pending->plan_code) {
                $plan = collect(app(Catalog::class)->list(true))->firstWhere('code',$pending->plan_code);
                if ($plan) {
                    DB::table('inventory_company_subscriptions')->insert([
                        'company_id'=>$company->id,'plan_version_id'=>$plan->id,
                        'snapshot'=>json_encode(['code'=>$plan->code,'version'=>$plan->version,'terms'=>$plan->terms],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                        'status'=>'pending_payment','starts_at'=>null,'ends_at'=>null,
                        'created_at'=>now(),'updated_at'=>now(),
                    ]);
                }
            }
            foreach (json_decode($pending->legal_consents?:'[]',true)?:[] as $consent) {
                $doc=DB::table('inventory_legal_versions')->where('id',$consent['legal_version_id']??0)->first();
                if (!$doc||$doc->status!=='published'||$doc->sha256!==($consent['sha256']??''))continue;
                DB::table('inventory_legal_consents')->insertOrIgnore([
                    'user_id'=>$user->id,'legal_version_id'=>$doc->id,'sha256'=>$doc->sha256,
                    'action'=>$consent['action'],'source'=>'inventory-registration',
                    'locale'=>$consent['locale'],'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
            DB::table('inventory_registration_requests')->where('id',$pending->id)->delete();
            Access::audit($user->id,$company->id,'registration.verified','company:'.$company->id);
            return ['ok'=>true,'user_id'=>$user->id];
        },3);
        return response()->json($result);
    }

    public function resend(Request $request): JsonResponse
    {
        $this->enabled();
        $input = $request->validate(['email'=>'required|email|max:255']);
        $email = Str::lower(trim($input['email']));
        $pending = DB::table('inventory_registration_requests')->where('email',$email)->first();
        if ($pending && !User::where('email',$email)->exists()) {
            $token = Str::random(64);
            DB::table('inventory_registration_requests')->where('id',$pending->id)->update([
                'token_hash'=>hash('sha256',$token),'expires_at'=>now()->addDay(),'updated_at'=>now(),
            ]);
            try {
                $this->sendVerification($email,$token,$pending->locale??'ru');
            } catch (\Throwable $exception) {
                report($exception);
                abort(503,'Verification email is temporarily unavailable.');
            }
        }
        return response()->json(['message'=>'Check your email.'],202);
    }
}
