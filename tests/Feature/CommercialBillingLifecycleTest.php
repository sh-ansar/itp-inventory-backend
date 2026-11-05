<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CommercialBillingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): array
    {
        $user=User::create([
            'name'=>'Billing QA','email'=>'billing-admin@example.test',
            'password'=>Hash::make('TestPassword123!'),'is_active'=>true,
        ]);
        $company=Company::create(['id'=>(string)Str::uuid(),'name'=>'Billing QA Company']);
        CompanyMembership::create([
            'user_id'=>$user->id,'company_id'=>$company->id,'role_code'=>'company_admin',
        ]);
        DB::table('inventory_platform_grants')->insert([
            'user_id'=>$user->id,'permission'=>'platform.manage',
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $this->actingAs($user);
        return [$user,$company];
    }

    private function profile(string $name): array
    {
        return [
            'name'=>$name,'bin'=>'123456789012','address'=>'Astana',
            'bank'=>'Test Bank','iban'=>'KZ123456789','bic'=>'TESTKZ',
            'email'=>'billing@example.test','phone'=>'+77770000000',
        ];
    }

    public function test_supplier_and_buyer_details_are_tenant_scoped_and_idempotent(): void
    {
        [, $company]=$this->admin();
        $first=$this->profile('ITP Mining');
        $this->putJson('/api/v1/platform-admin/billing/supplier',$first)->assertOk();
        $this->putJson('/api/v1/platform-admin/billing/supplier',$first)->assertOk();
        $this->assertSame(1,DB::table('inventory_billing_profiles')->where('profile_key','supplier')->count());
        $this->putJson('/api/v1/platform-admin/organizations/'.$company->id.'/billing',
            $this->profile('Billing QA Company'))->assertOk();
        $this->putJson('/api/v1/platform-admin/organizations/'.$company->id.'/billing',
            $this->profile('Billing QA Company Updated'))->assertOk();
        $this->assertSame(2,DB::table('inventory_billing_profiles')->count());
        $this->assertSame('Billing QA Company Updated',
            json_decode(DB::table('inventory_billing_profiles')
                ->where('profile_key','company:'.$company->id)->value('details'),true)['name']);
    }

    public function test_invoice_pdf_and_payment_review_have_atomic_lifecycle(): void
    {
        [$user,$company]=$this->admin();
        Storage::fake('local');
        $this->putJson('/api/v1/platform-admin/billing/supplier',
            $this->profile('ITP Mining'))->assertOk();
        $this->putJson('/api/v1/platform-admin/organizations/'.$company->id.'/billing',
            $this->profile('Buyer QA'))->assertOk();

        $invoice=$this->postJson('/api/v1/platform-admin/invoices',[
            'company_id'=>$company->id,
            'period_from'=>'2026-11-01','period_to'=>'2026-11-30','due_date'=>'2026-12-05',
            'basis'=>'Asset management subscription',
            'items'=>[['description'=>'Inventory team license','quantity'=>2,'unit_minor'=>450000]],
        ])->assertCreated()->json('data');
        $id=$invoice['id'];
        $this->assertSame('draft',$invoice['status']);
        $this->assertSame(900000,$invoice['total_minor']);
        $this->putJson('/api/v1/platform-admin/invoices/'.$id.'/status',[
            'status'=>'issued',
        ])->assertStatus(409);
        config()->set('inventory.invoice_issuance_enabled',true);
        $this->putJson('/api/v1/platform-admin/invoices/'.$id.'/status',[
            'status'=>'issued',
        ])->assertOk();
        $this->assertDatabaseHas('inventory_invoices',[
            'id'=>$id,'number'=>'INV-'.now()->year.'-'.str_pad((string)$id,6,'0',STR_PAD_LEFT),
            'status'=>'issued',
        ]);

        $pdf=$this->get('/api/v1/platform-admin/invoices/'.$id.'/pdf');
        $pdf->assertOk()->assertHeader('Content-Type','application/pdf');
        $this->assertStringStartsWith('%PDF-', (string)$pdf->baseResponse->getContent());

        $file=UploadedFile::fake()->createWithContent('payment.pdf',
            "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
        $response=$this->post('/api/v1/commercial/invoices/'.$id.'/proof',
            ['file'=>$file],['Accept'=>'application/json'])->assertCreated();
        $proofId=$response->json('id');
        $this->assertDatabaseHas('inventory_payment_proofs',[
            'id'=>$proofId,'invoice_id'=>$id,'status'=>'pending',
        ]);
        $this->putJson('/api/v1/platform-admin/proofs/'.$proofId.'/review',[
            'status'=>'accepted','review_note'=>'Bank statement confirmed',
        ])->assertOk();
        $this->assertDatabaseHas('inventory_invoices',['id'=>$id,'status'=>'paid']);
        $this->putJson('/api/v1/platform-admin/proofs/'.$proofId.'/review',[
            'status'=>'accepted',
        ])->assertStatus(409);
        $this->assertDatabaseHas('inventory_commercial_audit',[
            'company_id'=>$company->id,'action'=>'invoice.proof.accepted',
        ]);
    }

    public function test_verification_link_creates_company_only_after_confirmation(): void
    {
        Mail::fake();
        config()->set('inventory.browser_login_enabled',true);
        config()->set('inventory.public_registration_enabled',true);
        $data=[
            'name'=>'Inventory New User','email'=>'verified-user@example.test',
            'company_name'=>'Verified Company','password'=>'StrongPassword123!',
            'password_confirmation'=>'StrongPassword123!',
            'locale'=>'ru','plan_code'=>null,
        ];
        $this->postJson('/auth/register',$data)->assertAccepted();
        $this->assertDatabaseMissing('users',['email'=>$data['email']]);
        $token=str_repeat('A',64);
        DB::table('inventory_registration_requests')->where('email',$data['email'])
            ->update(['token_hash'=>hash('sha256',$token)]);
        $result=$this->postJson('/auth/verify-email',['token'=>$token])->assertOk();
        $user=User::where('email',$data['email'])->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check($data['password'],$user->password));
        $this->assertDatabaseHas('companies',['name'=>'Verified Company']);
        $this->assertDatabaseHas('company_memberships',[
            'user_id'=>$user->id,'role_code'=>'company_admin',
        ]);
        $this->assertDatabaseCount('inventory_registration_requests',0);
        $this->postJson('/auth/verify-email',['token'=>$token])->assertStatus(422);
    }

    public function test_registration_requires_explicit_legal_consent_and_preserves_version(): void
    {
        Mail::fake();
        config()->set('inventory.browser_login_enabled',true);
        config()->set('inventory.public_registration_enabled',true);
        $id=DB::table('inventory_legal_versions')->insertGetId([
            'type'=>'privacy','locale'=>'ru','jurisdiction'=>'KZ','version'=>1,
            'title'=>'Privacy terms for Inventory',
            'body'=>str_repeat('Private asset information. ',5),
            'acceptance'=>'consented','required'=>true,
            'effective_at'=>now()->subHour()->toDateTimeString(),
            'status'=>'published','published_at'=>now(),
            'sha256'=>hash('sha256','privacy-1'),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $data=[
            'name'=>'Consent QA','email'=>'consent-user@example.test',
            'company_name'=>'Consent QA Company','password'=>'StrongPassword123!',
            'password_confirmation'=>'StrongPassword123!',
            'locale'=>'ru',
        ];
        $this->postJson('/auth/register',$data)->assertStatus(422);
        $data['legal_choices']=['privacy'=>true];
        $this->postJson('/auth/register',$data)->assertAccepted();
        $token=str_repeat('B',64);
        DB::table('inventory_registration_requests')->where('email',$data['email'])
            ->update(['token_hash'=>hash('sha256',$token)]);
        $this->postJson('/auth/verify-email',['token'=>$token])->assertOk();
        $user=User::where('email',$data['email'])->firstOrFail();
        $this->assertDatabaseHas('inventory_legal_consents',[
            'user_id'=>$user->id,'legal_version_id'=>$id,
            'source'=>'inventory-registration',
            'sha256'=>hash('sha256','privacy-1'),
        ]);
    }
}
