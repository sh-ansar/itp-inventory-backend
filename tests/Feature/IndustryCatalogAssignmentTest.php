<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class IndustryCatalogAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_asset_manufacturer_model_and_department_are_company_scoped(): void
    {
        $admin=User::factory()->create(['is_active'=>true]);
        $a=Company::create(['id'=>(string) Str::uuid(),'name'=>'Company A']);
        $b=Company::create(['id'=>(string) Str::uuid(),'name'=>'Company B']);
        $admin->companyMemberships()->create(['company_id'=>$a->id,'role_code'=>'company_admin']);
        $admin->companyMemberships()->create(['company_id'=>$b->id,'role_code'=>'company_admin']);
        $this->actingAs($admin);
        $root='/api/v1/companies/'.$a->id;
        $create=fn (string $kind, array $data) =>
            $this->postJson($root.'/industry-catalogs/'.$kind,$data)->assertCreated()->json('data');
        $dell=$create('manufacturer',['code'=>'dell','name'=>'Dell']);
        $lenovo=$create('manufacturer',['code'=>'lenovo','name'=>'Lenovo']);
        $precision=$create('model',[
            'code'=>'precision','name'=>'Precision 7680','parent_id'=>$dell['id'],
        ]);
        $it=$create('department',['code'=>'it','name'=>'IT Department']);

        $this->postJson($root.'/assets',[
            'inventory_number'=>'BAD-1','name'=>'Wrong model',
            'manufacturer_id'=>$lenovo['id'],'model_id'=>$precision['id'],
        ])->assertUnprocessable()->assertJsonValidationErrors('model_id');
        $asset=$this->postJson($root.'/assets',[
            'inventory_number'=>'GOOD-1','name'=>'Dell laptop',
            'manufacturer_id'=>$dell['id'],'model_id'=>$precision['id'],
        ])->assertCreated()->assertJsonPath('data.manufacturer_id',$dell['id'])
            ->assertJsonPath('data.model_id',$precision['id'])->json('data');
        $person=$this->postJson($root.'/responsible-people',[
            'code'=>'EMP-1','name'=>'Employee One','department_id'=>$it['id'],
        ])->assertCreated()->assertJsonPath('data.department_id',$it['id'])->json('data');
        $this->postJson('/api/v1/companies/'.$b->id.'/responsible-people',[
            'code'=>'EMP-2','name'=>'Employee Two','department_id'=>$it['id'],
        ])->assertUnprocessable()->assertJsonValidationErrors('department_id');
        $this->postJson('/api/v1/companies/'.$b->id.'/assets',[
            'inventory_number'=>'BAD-2','name'=>'Cross tenant',
            'manufacturer_id'=>$dell['id'],
        ])->assertUnprocessable()->assertJsonValidationErrors('manufacturer_id');

        $this->postJson($root.'/industry-catalogs/model/'.$precision['id'].'/archive',[
            'version'=>1,
        ])->assertOk();
        $this->putJson($root.'/assets/'.$asset['id'],[
            'version'=>$asset['version'],'inventory_number'=>'GOOD-1',
            'name'=>'Dell laptop updated','manufacturer_id'=>$dell['id'],
            'model_id'=>$precision['id'],
        ])->assertOk()->assertJsonPath('data.model_id',$precision['id']);
        $this->postJson($root.'/assets',[
            'inventory_number'=>'BAD-3','name'=>'Cannot reuse archived model',
            'manufacturer_id'=>$dell['id'],'model_id'=>$precision['id'],
        ])->assertUnprocessable()->assertJsonValidationErrors('model_id');
        $this->postJson($root.'/industry-catalogs/department/'.$it['id'].'/archive',[
            'version'=>1,
        ])->assertOk();
        $this->putJson($root.'/responsible-people/'.$person['id'],[
            'version'=>$person['version'],'code'=>'EMP-1','name'=>'Employee One updated',
            'department_id'=>$it['id'],
        ])->assertOk();
    }
}
