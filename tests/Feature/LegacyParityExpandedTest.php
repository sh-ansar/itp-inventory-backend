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

final class LegacyParityExpandedTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::create(['id'=>(string) Str::uuid(),'name'=>Str::random(10)]);
    }
    private function member(Company $company,string $role='company_admin'): User
    {
        $user=User::factory()->create(['is_active'=>true]);
        $user->companyMemberships()->create(['company_id'=>$company->id,'role_code'=>$role]);
        return $user;
    }
    private function prefix(Company $company): string
    {
        return '/api/v1/companies/'.$company->id;
    }
    private function asset(Company $company,string $number): array
    {
        return $this->postJson($this->prefix($company).'/assets',
            ['name'=>'Computer '.$number,'inventory_number'=>$number])->assertCreated()->json('data');
    }

    public function test_bulk_moves_preserve_movements_and_reject_partial_stale_batches(): void
    {
        $company=$this->company();
        $this->actingAs($this->member($company));
        $a=$this->asset($company,'A');$b=$this->asset($company,'B');
        $to=$this->postJson($this->prefix($company).'/locations',[
            'code'=>'OFFICE-1','kind'=>'physical','name'=>'Office one',
        ])->assertCreated()->json('id');
        $url=$this->prefix($company).'/assets/bulk-move';
        $request=[
            'assets'=>[['id'=>$a['id'],'version'=>1],['id'=>$b['id'],'version'=>999]],
            'to_location_id'=>$to,'reason'=>'Transfer all office laptops',
        ];
        $this->postJson($url,$request)->assertStatus(409);
        $this->assertDatabaseCount('asset_movements',0);
        $request['assets'][1]['version']=1;
        $this->postJson($url,$request)->assertOk()->assertJsonPath('moved',2);
        $this->assertDatabaseCount('asset_movements',2);
        $this->assertDatabaseHas('assets',['id'=>$a['id'],'physical_location_id'=>$to,'version'=>2]);
        $this->getJson($this->prefix($company).'/assets/'.$b['id'].'/movements')
            ->assertOk()->assertJsonPath('data.0.to_location_name','Office one');
        $this->postJson($url,$request)->assertStatus(409);
    }

    public function test_membership_grant_and_revocation_are_scoped_and_audited(): void
    {
        $company=$this->company();$other=$this->company();
        $admin=$this->member($company);$account=User::factory()->create(['is_active'=>true]);
        $this->actingAs($admin);
        $root=$this->prefix($company).'/members';
        $this->postJson($root,[
            'email'=>$account->email,'role_code'=>'viewer','reason'=>'Grant access to inventory',
        ])->assertCreated()->assertJsonPath('user_id',$account->id);
        $this->assertDatabaseHas('company_memberships',[
            'company_id'=>$company->id,'user_id'=>$account->id,'role_code'=>'viewer',
        ]);
        $this->postJson($root,[
            'email'=>$account->email,'role_code'=>'viewer','reason'=>'Duplicate invitation',
        ])->assertStatus(409);
        $this->getJson($this->prefix($other).'/members')->assertNotFound();
        $this->deleteJson($root.'/'.$account->id,[
            'reason'=>'User no longer needs Inventory',
        ])->assertOk();
        $this->assertDatabaseMissing('company_memberships',[
            'company_id'=>$company->id,'user_id'=>$account->id,
        ]);
        $this->assertDatabaseHas('company_membership_events',[
            'company_id'=>$company->id,'target_user_id'=>$account->id,'event_type'=>'member_removed',
        ]);
        $this->deleteJson($root.'/'.$admin->id,['reason'=>'Self removal prohibited'])->assertStatus(409);
    }

    public function test_completed_stocktake_accepts_signed_attachment_and_xlsx_download(): void
    {
        Storage::fake('local');
        $company=$this->company();$other=$this->company();
        $admin=$this->member($company);$this->actingAs($admin);
        $asset=$this->asset($company,'STK-2');
        $session=$this->postJson($this->prefix($company).'/inventory-sessions',[
            'name'=>'Annual asset count','asset_ids'=>[$asset['id']],
        ])->assertCreated()->json('data');
        $base=$this->prefix($company).'/inventory-sessions/'.$session['id'];
        $this->postJson($base.'/complete',['version'=>1])->assertOk()->assertJsonPath('data.version',2);
        $signed=UploadedFile::fake()->createWithContent('report.pdf',"%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        $attachment=$this->post($base.'/documents',[
            'version'=>'2','file'=>$signed,
        ],['Accept'=>'application/json'])->assertCreated()->json('data');
        $this->assertSame(hash('sha256',"%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF"),$attachment['sha256']);
        $this->getJson($base.'/documents')->assertOk()->assertJsonCount(1,'data');
        $this->get($base.'/documents/'.$attachment['id'])->assertOk();
        $this->getJson($base.'/events')->assertOk()
            ->assertJsonPath('data.0.event_type','document_uploaded');
        $export=$this->get($base.'/report.xlsx')->assertOk();
        $zip=new ZipArchive();
        $this->assertTrue($zip->open($export->baseResponse->getFile()->getPathname())===true);
        $this->assertStringContainsString('STK-2',$zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();
        $this->actingAs($this->member($other,'viewer'));
        $this->get($base.'/documents/'.$attachment['id'])->assertNotFound();
        $this->get($base.'/report.xlsx')->assertNotFound();
    }
}
