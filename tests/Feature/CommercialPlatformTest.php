<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CommercialPlatformTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email='qa-inventory@example.test'): User
    {
        return User::create([
            'name'=>'Inventory QA','email'=>$email,
            'password'=>Hash::make('TestPassword123!'),'is_active'=>true,
        ]);
    }

    private function grant(User $user,string $permission='platform.manage'): void
    {
        DB::table('inventory_platform_grants')->insert([
            'user_id'=>$user->id,'permission'=>$permission,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    private function company(User $user): Company
    {
        $company=Company::create(['id'=>(string)Str::uuid(),'name'=>'Tenant '.$user->id]);
        CompanyMembership::create([
            'user_id'=>$user->id,'company_id'=>$company->id,'role_code'=>'company_admin',
        ]);
        return $company;
    }

    private function planPayload(string $code='starter'): array
    {
        return [
            'code'=>$code,
            'names'=>['ru'=>'Старт','en'=>'Starter','ge'=>'საწყისი'],
            'description'=>'Asset management for small teams',
            'price_minor'=>900000,'currency'=>'KZT','period_months'=>1,
            'is_public'=>true,'display_order'=>1,
            'limits'=>['users'=>5,'companies'=>1,
                'storage_bytes'=>1073741824,'max_file_bytes'=>10485760],
            'modules'=>[
                'assets'=>true,'disposals'=>false,'facilities'=>true,
                'references'=>true,'people'=>true,'warehouses'=>false,
                'stocktakes'=>true,'reports'=>true,
            ],
        ];
    }

    public function test_public_catalog_is_empty_and_private_endpoints_deny_guests(): void
    {
        $this->getJson('/api/v1/commercial/plans')->assertOk()->assertJson(['data'=>[]]);
        $this->getJson('/api/v1/platform-admin/overview')->assertUnauthorized();
        $this->getJson('/api/v1/platform-admin/organizations')->assertUnauthorized();
    }

    public function test_regular_user_is_denied_super_admin_and_grant_is_explicit(): void
    {
        $user=$this->user();$this->actingAs($user);
        $this->getJson('/api/v1/platform-admin/overview')->assertForbidden();
        $this->postJson('/api/v1/platform-admin/plans',$this->planPayload())->assertForbidden();
        $this->grant($user);
        $this->getJson('/api/v1/platform-admin/overview')->assertOk()
            ->assertJsonPath('permissions.0','platform.manage');
    }

    public function test_immutable_plans_do_not_leak_drafts_and_published_snapshots_are_independent(): void
    {
        $user=$this->user();$company=$this->company($user);$this->grant($user);
        $this->actingAs($user);
        $draft=$this->postJson('/api/v1/platform-admin/plans',$this->planPayload())
            ->assertCreated()->json('data');
        $this->assertSame('draft',$draft['status']);
        $this->getJson('/api/v1/commercial/plans')->assertJsonCount(0,'data');

        $this->putJson('/api/v1/platform-admin/plans/'.$draft['id'].'/status',[
            'status'=>'published',
        ])->assertOk();
        $this->getJson('/api/v1/commercial/plans')->assertJsonCount(1,'data')
            ->assertJsonPath('data.0.code','starter');

        $this->putJson('/api/v1/platform-admin/organizations/'.$company->id.'/subscription',[
            'plan_version_id'=>$draft['id'],
        ])->assertOk();
        $snapshot=DB::table('inventory_company_subscriptions')->where('company_id',$company->id)->first();
        $this->assertSame('active',$snapshot->status);
        $this->assertSame(5,json_decode($snapshot->snapshot,true)['terms']['limits']['users']);

        $next=$this->planPayload();
        $next['price_minor']=2000000;
        $next['limits']['users']=20;
        $version2=$this->postJson('/api/v1/platform-admin/plans',$next)->assertCreated()->json('data');
        $this->assertSame(2,$version2['version']);
        $this->assertSame(5,json_decode(DB::table('inventory_company_subscriptions')
            ->where('company_id',$company->id)->value('snapshot'),true)['terms']['limits']['users']);
    }

    public function test_invalid_tariff_module_and_georgian_translation_are_rejected(): void
    {
        $user=$this->user();$this->grant($user);$this->actingAs($user);
        $payload=$this->planPayload();
        unset($payload['names']['ge']);unset($payload['modules']['stocktakes']);
        $this->postJson('/api/v1/platform-admin/plans',$payload)->assertUnprocessable()
            ->assertJsonValidationErrors(['names.ge','modules.stocktakes']);
    }

    public function test_tenant_isolation_applies_to_company_invoices(): void
    {
        $a=$this->user('alpha-inventory@example.test');
        $b=$this->user('beta-inventory@example.test');
        $ac=$this->company($a);$bc=$this->company($b);
        $invoice=DB::table('inventory_invoices')->insertGetId([
            'company_id'=>$ac->id,'status'=>'issued','currency'=>'KZT',
            'total_minor'=>1000,'period_from'=>now()->toDateString(),
            'period_to'=>now()->toDateString(),'due_date'=>now()->toDateString(),
            'basis'=>'QA invoice',
            'items'=>json_encode([['description'=>'Inventory service','quantity'=>1,'unit_minor'=>1000]]),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $this->actingAs($b);
        $this->getJson('/api/v1/commercial/invoices?company_id='.$ac->id)->assertForbidden();
        $this->getJson('/api/v1/commercial/invoices/'.$invoice.'/pdf')->assertForbidden();
        $this->postJson('/api/v1/commercial/invoices/'.$invoice.'/proof',[])->assertForbidden();
        $this->getJson('/api/v1/commercial/invoices?company_id='.$bc->id)->assertOk()
            ->assertJsonPath('total',0);
    }

    public function test_subscriptions_protect_disabled_modules_and_expiration_without_affecting_legacy(): void
    {
        $user=$this->user();$company=$this->company($user);
        $this->actingAs($user);
        // No subscription is the legacy Inventory behavior.
        $this->getJson('/api/v1/companies/'.$company->id.'/assets')->assertOk();
        DB::table('inventory_company_subscriptions')->insert([
            'company_id'=>$company->id,'status'=>'active',
            'snapshot'=>json_encode(['code'=>'starter','version'=>1,'terms'=>[
                'modules'=>['assets'=>false],'limits'=>['users'=>1],
            ]]),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $this->getJson('/api/v1/companies/'.$company->id.'/assets')->assertForbidden();
        DB::table('inventory_company_subscriptions')->where('company_id',$company->id)
            ->update(['status'=>'pending_payment']);
        $this->getJson('/api/v1/companies/'.$company->id.'/assets')->assertStatus(402);
    }

    public function test_legal_documents_require_explicit_approval_and_publication_and_consent(): void
    {
        $user=$this->user();$this->grant($user);$this->actingAs($user);
        $legal=[
            'type'=>'privacy','locale'=>'ru','jurisdiction'=>'KZ',
            'title'=>'Inventory Privacy Policy',
            'body'=>str_repeat('Privacy for company records. ',6),
            'acceptance'=>'accepted','required'=>true,
            'effective_at'=>now()->subHour()->toIso8601String(),
        ];
        $doc=$this->postJson('/api/v1/platform-admin/legal',$legal)->assertCreated()->json('data');
        $id=$doc['id'];
        $this->getJson('/api/v1/commercial/legal?locale=ru')->assertJsonCount(0,'data');
        $this->putJson('/api/v1/platform-admin/legal/'.$id.'/status',[
            'status'=>'approved','approval_note'=>'short',
        ])->assertStatus(422);
        $this->putJson('/api/v1/platform-admin/legal/'.$id.'/status',[
            'status'=>'approved','approval_note'=>'APPROVAL QA-20261105',
        ])->assertOk();
        $this->putJson('/api/v1/platform-admin/legal/'.$id.'/status',[
            'status'=>'published',
        ])->assertStatus(409);
        config()->set('inventory.legal_publication_enabled',true);
        $this->putJson('/api/v1/platform-admin/legal/'.$id.'/status',[
            'status'=>'published',
        ])->assertOk();
        $this->getJson('/api/v1/commercial/legal?locale=ru')->assertJsonCount(1,'data');
        $this->postJson('/api/v1/commercial/legal/accept',[
            'locale'=>'ru','jurisdiction'=>'KZ','choices'=>['privacy'=>false],
        ])->assertStatus(422);
        $this->postJson('/api/v1/commercial/legal/accept',[
            'locale'=>'ru','jurisdiction'=>'KZ','choices'=>['privacy'=>true],
        ])->assertOk();
        $this->assertDatabaseCount('inventory_legal_consents',1);
    }

    public function test_registration_requires_email_validation_and_does_not_create_users_early(): void
    {
        Mail::fake();
        config()->set('inventory.browser_login_enabled',true);
        $data=[
            'name'=>'Registration QA','email'=>'new-inventory@example.test',
            'company_name'=>'QA Company',
            'password'=>'PasswordStrong123!',
            'password_confirmation'=>'PasswordStrong123!',
            'locale'=>'ru','plan_code'=>null,
        ];
        $this->postJson('/auth/register',$data)->assertStatus(503);
        config()->set('inventory.public_registration_enabled',true);
        $this->postJson('/auth/register',$data)->assertAccepted();
        $this->assertDatabaseMissing('users',['email'=>'new-inventory@example.test']);
        $this->assertDatabaseCount('inventory_registration_requests',1);
        $pending=DB::table('inventory_registration_requests')
            ->where('email','new-inventory@example.test')->first();
        $this->assertNotSame($pending->token_hash,$data['password']);
        $this->assertTrue(strlen($pending->token_hash)===64);
        $this->postJson('/auth/verify-email',['token'=>str_repeat('0',64)])->assertStatus(422);
    }

    public function test_cannot_remove_last_platform_administrator(): void
    {
        $admin=$this->user();$this->grant($admin);
        $this->actingAs($admin);
        $id=DB::table('inventory_platform_grants')->where('user_id',$admin->id)->value('id');
        $this->deleteJson('/api/v1/platform-admin/grants/'.$id)->assertStatus(409);
        $this->assertDatabaseHas('inventory_platform_grants',['id'=>$id]);
    }
}
