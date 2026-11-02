<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

final class LegacyParityWorkflowsTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::create(['id'=>(string) Str::uuid(),'name'=>Str::random(12)]);
    }
    private function user(Company $company,string $role): User
    {
        $user=User::factory()->create(['is_active'=>true]);
        $user->companyMemberships()->create(['company_id'=>$company->id,'role_code'=>$role]);
        return $user;
    }
    private function base(Company $company): string
    {
        return '/api/v1/companies/'.$company->id;
    }
    private function asset(Company $company,string $number): array
    {
        return $this->postJson($this->base($company).'/assets',[
            'inventory_number'=>$number,'name'=>'Laptop '.$number,
        ])->assertCreated()->json('data');
    }

    public function test_excel_export_is_real_xlsx_and_tenant_scoped(): void
    {
        $a=$this->company();$b=$this->company();
        $admin=$this->user($a,'company_admin');
        $this->actingAs($admin);
        $this->asset($a,'=2+3');
        $this->actingAs($this->user($b,'company_admin'));
        $this->asset($b,'SECRET-ITEM');
        $this->actingAs($admin);
        $response=$this->get($this->base($a).'/assets/export.xlsx');
        $response->assertOk()->assertHeader('Content-Type','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $file=$response->baseResponse->getFile()->getPathname();
        $zip=new ZipArchive();
        $this->assertTrue($zip->open($file)===true);
        $sheet=$zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertIsString($sheet);
        $this->assertStringContainsString('=2+3',$sheet);
        $this->assertStringContainsString('inlineStr',$sheet);
        $this->assertStringNotContainsString('<f>', $sheet);
        $this->assertStringNotContainsString('SECRET-ITEM',$sheet);
        $zip->close();
        $this->actingAs($this->user($b,'viewer'));
        $this->get($this->base($a).'/assets/export.xlsx')->assertNotFound();
    }

    public function test_batch_reassignment_is_atomic_audited_and_versioned(): void
    {
        $a=$this->company();$b=$this->company();
        $admin=$this->user($a,'company_admin');
        $this->actingAs($admin);
        $first=$this->asset($a,'A1');$second=$this->asset($a,'A2');
        $endpoint=$this->base($a).'/assets/bulk-responsible';
        $input=[
            'assets'=>[['id'=>$first['id'],'version'=>1],['id'=>$second['id'],'version'=>99]],
            'responsible_person_id'=>null,'reason'=>'Reconcile custodian assignment',
        ];
        $this->postJson($endpoint,$input)->assertStatus(409);
        $this->assertDatabaseHas('assets',['id'=>$first['id'],'version'=>1]);
        $input['assets'][1]['version']=1;
        $this->postJson($endpoint,$input)->assertOk()->assertJsonPath('updated',0);
        $this->actingAs($this->user($b,'inventory_manager'));
        $this->postJson($endpoint,$input)->assertNotFound();
        $this->actingAs($this->user($a,'viewer'));
        $this->postJson($endpoint,$input)->assertForbidden();
    }

    public function test_company_admin_can_manage_roles_but_cannot_demote_last_admin(): void
    {
        $company=$this->company();
        $admin=$this->user($company,'company_admin');
        $viewer=$this->user($company,'viewer');
        $this->actingAs($admin);
        $members=$this->base($company).'/members';
        $this->getJson($members)->assertOk()->assertJsonCount(2,'data');
        $this->putJson($members.'/'.$admin->id,[
            'role_code'=>'viewer','reason'=>'Delegation change request',
        ])->assertUnprocessable();
        $this->putJson($members.'/'.$viewer->id,[
            'role_code'=>'inventory_manager','reason'=>'Assigned inventory duties',
        ])->assertOk()->assertJsonPath('changed',true);
        $this->assertDatabaseHas('company_membership_events',[
            'company_id'=>$company->id,'target_user_id'=>$viewer->id,
            'before_role'=>'viewer','after_role'=>'inventory_manager',
        ]);
        $this->actingAs($viewer);
        $this->getJson($members)->assertForbidden();
    }

    public function test_stocktake_documents_require_completed_session_and_company_membership(): void
    {
        Storage::fake('local');
        $company=$this->company();
        $other=$this->company();
        $admin=$this->user($company,'company_admin');
        $this->actingAs($admin);
        $asset=$this->asset($company,'STK-01');
        $session=$this->postJson($this->base($company).'/inventory-sessions',[
            'name'=>'Monthly stocktake','asset_ids'=>[$asset['id']],
        ])->assertCreated()->json('data');
        $root=$this->base($company).'/inventory-sessions/'.$session['id'];
        $pdf=UploadedFile::fake()->createWithContent('signed.pdf',"%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        $this->post($root.'/documents',[
            'version'=>$session['version'],'file'=>$pdf,
        ],['Accept'=>'application/json'])->assertStatus(409);
        $this->getJson($root.'/documents')->assertOk()->assertJsonCount(0,'data');
        $this->actingAs($this->user($other,'viewer'));
        $this->getJson($root.'/documents')->assertNotFound();
        $this->actingAs($this->user($company,'viewer'));
        $this->post($root.'/documents',[
            'version'=>$session['version'],
            'file'=>UploadedFile::fake()->createWithContent('signed.pdf',"%PDF-1.4\n%%EOF"),
        ],['Accept'=>'application/json'])->assertForbidden();
    }
}
