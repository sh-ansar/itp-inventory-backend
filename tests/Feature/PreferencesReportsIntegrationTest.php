<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PreferencesReportsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::create(['id'=>(string)Str::uuid(),'name'=>Str::random(12)]);
    }

    private function actor(Company $company, string $role='viewer'): User
    {
        $user=User::factory()->create(['is_active'=>true]);
        $user->companyMemberships()->create(['company_id'=>$company->id,'role_code'=>$role]);
        return $user;
    }

    private function root(Company $company): string
    {
        return '/api/v1/companies/'.$company->id;
    }

    private function token(Company $company,array $abilities=['assets:read','references:read','stock:read','imports:preview']): string
    {
        $secret='inv_'.Str::random(64);
        DB::table('inventory_integration_keys')->insert([
            'id'=>(string)Str::uuid(),'company_id'=>$company->id,'system_code'=>'1c',
            'name'=>'QA accounting integration','token_hash'=>hash('sha256',$secret),
            'abilities'=>json_encode($abilities,JSON_THROW_ON_ERROR),
            'expires_at'=>now()->addDay(), 'created_at'=>now(),'updated_at'=>now(),
        ]);
        return $secret;
    }

    public function test_preferences_are_isolated_by_user_company_and_view_and_can_be_reset(): void
    {
        $a=$this->company(); $b=$this->company();
        $owner=$this->actor($a); $other=$this->actor($a);
        $this->actingAs($owner);
        $url=$this->root($a).'/preferences/assets';
        $this->getJson($url)->assertOk()->assertJsonPath('data.settings',null);
        $data=['settings'=>['per_page'=>50,'search'=>'laptops','sort_key'=>'name',
            'sort_direction'=>'desc','hidden_columns'=>['category_name','status']]];
        $this->putJson($url,$data)->assertOk()->assertJsonPath('data.settings.per_page',50);
        $this->getJson($url)->assertJsonPath('data.settings.search','laptops');
        $this->actingAs($other);
        $this->getJson($url)->assertJsonPath('data.settings',null);
        $this->actingAs($this->actor($b));
        $this->getJson($url)->assertNotFound();
        $this->actingAs($owner);
        $this->putJson($url,['settings'=>['hidden_columns'=>['name','actions','status']]])
            ->assertOk()->assertJsonPath('data.settings.hidden_columns',['status']);
        $this->putJson($url,['settings'=>['sort_key'=>'SQL;DROP TABLE assets']])
            ->assertUnprocessable();
        $this->putJson($url,['settings'=>['unknown'=>'secret']])
            ->assertUnprocessable();
        $this->deleteJson($url)->assertNoContent();
        $this->getJson($url)->assertJsonPath('data.settings',null);
        $this->getJson($this->root($a).'/preferences/invalid')->assertNotFound();
    }

    public function test_reports_group_only_the_current_company_and_csv_is_safe(): void
    {
        $a=$this->company();$b=$this->company();
        $admin=$this->actor($a,'company_admin');
        $this->actingAs($admin);
        $this->postJson($this->root($a).'/assets',[
            'inventory_number'=>'=2+3','name'=>'Test printer',
        ])->assertCreated();
        $this->postJson($this->root($a).'/assets',[
            'inventory_number'=>'INV-002','name'=>'Test PC',
        ])->assertCreated();
        $this->actingAs($this->actor($b,'company_admin'));
        $this->postJson($this->root($b).'/assets',[
            'inventory_number'=>'TOP-SECRET','name'=>'Other-company asset',
        ])->assertCreated();

        $this->actingAs($admin);
        $url=$this->root($a).'/reports/summary';
        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.assets.total',2)
            ->assertJsonPath('data.assets.by_status.0.total',2)
            ->assertJsonPath('data.warehouses.total_items',0);
        $csv=$this->get($url.'.csv');
        $csv->assertOk()->assertHeader('Content-Type','text/csv; charset=UTF-8');
        $data=$csv->streamedContent();
        $this->assertStringNotContainsString('TOP-SECRET',$data);
        $this->assertStringContainsString('Summary report',$data);
        $this->actingAs($this->actor($b));
        $this->getJson($url)->assertNotFound();
    }

    public function test_external_api_is_disabled_by_default_and_tokens_are_tenant_scoped(): void
    {
        $a=$this->company();$b=$this->company();$secret=$this->token($a);
        $url='/api/v1/external/companies/'.$a->id;
        $headers=['Authorization'=>'Bearer '.$secret];
        $this->withHeaders($headers)->getJson($url.'/metadata')->assertNotFound();
        config()->set('inventory.integrations_enabled',true);
        $this->withHeaders($headers)->getJson($url.'/metadata')->assertOk()
            ->assertJsonPath('data.company_id',$a->id)
            ->assertJsonPath('data.import_enabled',false);
        $this->withHeaders($headers)->getJson($url.'/assets')->assertOk()
            ->assertJsonPath('meta.schema_version','1.0');
        $this->withHeaders($headers)->getJson($url.'/references')->assertOk();
        $this->withHeaders($headers)->getJson($url.'/stock')->assertOk();
        $this->withHeaders($headers)->getJson('/api/v1/external/companies/'.$b->id.'/assets')->assertUnauthorized();
        $this->withHeaders(['Authorization'=>'Bearer inv_'.Str::random(64)])
            ->getJson($url.'/assets')->assertUnauthorized();
        $this->withHeaders(['Authorization'=>'Bearer '.$this->token($a,['stock:read'])])
            ->getJson($url.'/assets')->assertForbidden();
    }

    public function test_accounting_preview_is_read_only_detects_duplicates_and_respects_revocation(): void
    {
        config()->set('inventory.integrations_enabled',true);
        $company=$this->company();$secret=$this->token($company);
        $url='/api/v1/external/companies/'.$company->id;
        $header=['Authorization'=>'Bearer '.$secret];
        $this->actingAs($this->actor($company,'company_admin'));
        $this->postJson($this->root($company).'/assets',[
            'inventory_number'=>'BOOK-01','name'=>'Book',
        ])->assertCreated();
        $this->withHeaders($header);
        $preview=$this->postJson($url.'/imports/preview',[
            'mode'=>'dry_run','entity_type'=>'asset','records'=>[
                ['external_id'=>'1c-1','inventory_number'=>'BOOK-01','name'=>'1C Book'],
                ['external_id'=>'1c-1','inventory_number'=>'BOOK-02','name'=>'Book 2'],
            ],
        ]);
        $preview->assertOk()->assertJsonPath('written',0)
            ->assertJsonPath('data.0.decision','manual_match_required')
            ->assertJsonPath('data.1.decision','conflict');
        $this->assertDatabaseCount('assets',1);
        $this->postJson($url.'/imports/preview',[
            'mode'=>'write','entity_type'=>'asset','records'=>[
                ['external_id'=>'X','inventory_number'=>'NEW','name'=>'Name'],
            ],
        ])->assertUnprocessable();
        DB::table('inventory_integration_keys')
            ->where('token_hash',hash('sha256',$secret))->update(['revoked_at'=>now()]);
        $this->withHeaders($header)->getJson($url.'/assets')->assertUnauthorized();
    }

    public function test_operator_issues_expiring_token_and_can_revoke_only_matching_company_key(): void
    {
        $company=$this->company();
        $foreign=$this->company();
        $this->artisan('inventory:integration-key', [
            'company'=>$company->id,'--system'=>'1c','--name'=>'Unit QA connector','--hours'=>24,
        ])->assertExitCode(0);
        $record=DB::table('inventory_integration_keys')->where('company_id',$company->id)->first();
        $this->assertNotNull($record);
        $this->assertSame(64,strlen($record->token_hash));
        $this->assertSame('1c',$record->system_code);
        $this->assertNull($record->revoked_at);
        $this->assertTrue(\Illuminate\Support\Carbon::parse($record->expires_at)->isFuture());
        $this->artisan('inventory:integration-key', [
            'company'=>$foreign->id,'--revoke'=>$record->id,
        ])->assertExitCode(1);
        $this->assertNull(DB::table('inventory_integration_keys')->where('id',$record->id)->value('revoked_at'));
        $this->artisan('inventory:integration-key', [
            'company'=>$company->id,'--revoke'=>$record->id,
        ])->assertExitCode(0);
        $this->assertNotNull(DB::table('inventory_integration_keys')->where('id',$record->id)->value('revoked_at'));
        $this->artisan('inventory:integration-key', [
            'company'=>$company->id,'--system'=>'1c','--hours'=>9000,
        ])->assertExitCode(1);
        $this->assertDatabaseCount('inventory_integration_keys',1);
    }

}
