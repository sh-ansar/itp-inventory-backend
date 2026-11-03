<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AssetCustomFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dynamic_fields_validate_types_categories_tenants_and_audit_version(): void
    {
        $user=User::factory()->create(['is_active'=>true]);
        $a=Company::create(['id'=>(string) Str::uuid(),'name'=>'A']);
        $b=Company::create(['id'=>(string) Str::uuid(),'name'=>'B']);
        $user->companyMemberships()->create(['company_id'=>$a->id,'role_code'=>'company_admin']);
        $user->companyMemberships()->create(['company_id'=>$b->id,'role_code'=>'viewer']);
        $this->actingAs($user);
        $root='/api/v1/companies/'.$a->id;
        $cat=$this->postJson($root.'/categories',['code'=>'IT','name'=>'IT devices'])
            ->assertCreated()->json('id');
        $f=$this->postJson($root.'/asset-fields',[
            'category_id'=>$cat,'code'=>'ram_gb','label_ru'=>'ОЗУ, ГБ',
            'field_type'=>'number','required'=>true,
        ])->assertCreated()->json('data');
        $this->postJson($root.'/asset-fields',[
            'category_id'=>$cat,'code'=>'ram_gb','label_ru'=>'Duplicate',
            'field_type'=>'number',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson($root.'/assets',[
            'inventory_number'=>'LAP-1','name'=>'Laptop','category_id'=>$cat,
        ])->assertUnprocessable()->assertJsonValidationErrors('attributes.'.$f['id']);
        $asset=$this->postJson($root.'/assets',[
            'inventory_number'=>'LAP-1','name'=>'Laptop','category_id'=>$cat,
            'attributes'=>[$f['id']=>16],
        ])->assertCreated()->json('data');
        $this->putJson($root.'/assets/'.$asset['id'],[
            'version'=>$asset['version'],'inventory_number'=>'LAP-1',
            'name'=>'Laptop Updated','category_id'=>$cat,
            'attributes'=>[$f['id']=>'not-number'],
        ])->assertUnprocessable()->assertJsonValidationErrors('attributes.'.$f['id']);
        $this->getJson($root.'/assets/'.$asset['id'])->assertOk()
            ->assertJsonPath('data.name','Laptop');
        $this->putJson($root.'/assets/'.$asset['id'].'/attributes',[
            'version'=>$asset['version'],'values'=>[$f['id']=>'invalid'],
        ])->assertUnprocessable();
        $this->putJson($root.'/assets/'.$asset['id'].'/attributes',[
            'version'=>$asset['version'],'values'=>[$f['id']=>32],
        ])->assertOk()->assertJsonPath('asset_version',$asset['version']+1);
        $this->putJson($root.'/assets/'.$asset['id'].'/attributes',[
            'version'=>$asset['version'],'values'=>[$f['id']=>64],
        ])->assertStatus(409);
        $this->getJson($root.'/assets/'.$asset['id'].'/attributes')
            ->assertOk()->assertJsonPath('data.'.$f['id'],32);
        $this->putJson('/api/v1/companies/'.$b->id.'/assets/'.$asset['id'].'/attributes',[
            'version'=>2,'values'=>[$f['id']=>12],
        ])->assertForbidden();
        $this->assertDatabaseHas('asset_events',[
            'company_id'=>$a->id,'asset_id'=>$asset['id'],'event_type'=>'attributes_updated',
        ]);
    }
}
