<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CompanyConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_company_admin_can_update_company_settings_and_versions(): void
    {
        $admin=User::factory()->create(['is_active'=>true]);
        $viewer=User::factory()->create(['is_active'=>true]);
        $one=Company::create(['id'=>(string) Str::uuid(),'name'=>'Company A']);
        $two=Company::create(['id'=>(string) Str::uuid(),'name'=>'Company B']);
        $admin->companyMemberships()->create(['company_id'=>$one->id,'role_code'=>'company_admin']);
        $viewer->companyMemberships()->create(['company_id'=>$one->id,'role_code'=>'viewer']);
        $viewer->companyMemberships()->create(['company_id'=>$two->id,'role_code'=>'company_admin']);
        $url='/api/v1/companies/'.$one->id.'/configuration';
        $payload=[
            'version'=>0,'legal_name'=>'Company A LLC','country_code'=>'KZ','timezone'=>'Asia/Almaty',
            'currency'=>'KZT','asset_number_prefix'=>'INV','document_number_prefix'=>'DOC',
            'default_locale'=>'ru',
        ];
        $this->actingAs($viewer)->putJson($url,$payload)->assertForbidden();
        $this->actingAs($admin)->getJson($url)->assertOk()->assertJsonPath('data.version',0);
        $this->putJson($url,$payload)->assertOk()->assertJsonPath('data.version',1);
        $this->putJson($url,$payload)->assertStatus(409);
        $this->getJson($url)->assertOk()->assertJsonPath('data.legal_name','Company A LLC');
        $this->actingAs($viewer)->getJson('/api/v1/companies/'.$two->id.'/configuration')
            ->assertOk()->assertJsonPath('data.version',0);
    }

    public function test_template_revision_publish_preview_tenant_and_html_safety(): void
    {
        $admin=User::factory()->create(['is_active'=>true]);
        $one=Company::create(['id'=>(string) Str::uuid(),'name'=>'Company A']);
        $two=Company::create(['id'=>(string) Str::uuid(),'name'=>'Company B']);
        $admin->companyMemberships()->create(['company_id'=>$one->id,'role_code'=>'company_admin']);
        $admin->companyMemberships()->create(['company_id'=>$two->id,'role_code'=>'viewer']);
        $this->actingAs($admin);
        $url='/api/v1/companies/'.$one->id.'/document-templates';
        $body='Company: {{company_name}}. Asset: {{asset_number}}.';
        $this->postJson($url,[
            'kind'=>'stocktake','locale'=>'ru','title'=>'Unsafe',
            'body'=>'<script>alert(1)</script>',
        ])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->postJson($url,[
            'kind'=>'stocktake','locale'=>'ru','title'=>'Unknown',
            'body'=>'Unknown {{api_secret}}',
        ])->assertUnprocessable()->assertJsonValidationErrors('body');
        $first=$this->postJson($url,[
            'kind'=>'stocktake','locale'=>'ru','title'=>'Stocktake 1','body'=>$body,
        ])->assertCreated()->assertJsonPath('data.revision',1)->json('data');
        $second=$this->postJson($url,[
            'kind'=>'stocktake','locale'=>'ru','title'=>'Stocktake 2','body'=>$body,
        ])->assertCreated()->assertJsonPath('data.revision',2)->json('data');
        $this->postJson($url.'/'.$first['id'].'/publish',[
            'revision'=>1,
        ])->assertOk()->assertJsonPath('data.status','published');
        $this->postJson($url.'/'.$second['id'].'/publish',[
            'revision'=>2,
        ])->assertOk()->assertJsonPath('data.status','published');
        $this->getJson($url)->assertOk()->assertJsonCount(2,'data');
        $this->assertDatabaseHas('inventory_document_templates',[
            'id'=>$first['id'],'status'=>'archived',
        ]);
        $this->postJson($url.'/'.$second['id'].'/preview',[
            'asset_number'=>'INV-001',
        ])->assertOk()->assertJsonPath('data.body','Company: Company A. Asset: INV-001.');
        $this->getJson('/api/v1/companies/'.$two->id.'/document-templates')
            ->assertOk()->assertJsonCount(0,'data');
        $this->postJson('/api/v1/companies/'.$two->id.'/document-templates/'.$second['id'].'/preview')
            ->assertNotFound();
    }
}
