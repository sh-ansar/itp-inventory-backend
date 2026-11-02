<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MultiAssetFiltersTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $name): Company
    {
        return Company::create(['id'=>(string)Str::uuid(),'name'=>$name]);
    }

    private function login(Company $company): void
    {
        $user=User::factory()->create(['is_active'=>true]);
        $user->companyMemberships()->create(['company_id'=>$company->id,'role_code'=>'company_admin']);
        $this->actingAs($user);
    }

    private function node(Company $company, string $name, string $type, ?string $parent=null): string
    {
        $id=(string)Str::uuid();
        DB::table('facility_nodes')->insert([
            'id'=>$id,'company_id'=>$company->id,'code'=>Str::random(12),
            'name'=>$name,'node_type'=>$type,'parent_id'=>$parent,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        return $id;
    }

    private function person(Company $company,string $name): string
    {
        $id=(string)Str::uuid();
        DB::table('responsible_people')->insert([
            'id'=>$id,'company_id'=>$company->id,'code'=>Str::random(12),
            'name'=>$name,'created_at'=>now(),'updated_at'=>now(),
        ]);
        return $id;
    }

    private function asset(Company $company,string $inventory,string $status,string $node,string $person): string
    {
        $id=(string)Str::uuid();
        DB::table('assets')->insert([
            'id'=>$id,'public_id'=>(string)Str::uuid(),'company_id'=>$company->id,
            'inventory_number'=>$inventory,'name'=>'QA multiselect '. $inventory,
            'status'=>$status,'facility_node_id'=>$node,'responsible_person_id'=>$person,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        return $id;
    }

    private function getFiltered(Company $company,string $endpoint,array $filter): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/v1/companies/'.$company->id.'/'.$endpoint.'?'.http_build_query($filter));
    }

    public function test_registry_excel_summary_and_print_share_the_same_multi_filters(): void
    {
        $company=$this->company('QA multi company');
        $root=$this->node($company,'Main Building','building');
        $room=$this->node($company,'Room 1','room',$root);
        $other=$this->node($company,'Annex','building');
        $alice=$this->person($company,'Alice');
        $bob=$this->person($company,'Bob');
        $this->asset($company,'MM-A','active',$room,$alice);
        $this->asset($company,'MM-B','disposed',$other,$bob);
        $this->asset($company,'MM-C','written_off',$other,$alice);
        $this->login($company);

        $filter=[
            'statuses'=>['active','disposed'],
            'facility_node_ids'=>[$root,$other],
            'responsible_person_ids'=>[$alice,$bob],
        ];
        $registry=$this->getFiltered($company,'assets',$filter)->assertOk();
        $registry->assertJsonPath('meta.total',2);
        $summary=$this->getFiltered($company,'reports/summary',$filter)->assertOk();
        $summary->assertJsonPath('data.assets.total',1);
        // Registry includes descendants by default; summary and print preserve their
        // longstanding exact-facility semantics, and do not broaden to child rooms.
        $print=$this->getFiltered($company,'reports/forms/asset-register',$filter)->assertOk();
        $print->assertJsonPath('data.row_count',1);
        $print->assertJsonPath('data.rows.0.inventory_number','MM-B');
        $export=$this->getFiltered($company,'assets/export.xlsx',$filter)->assertOk();
        $this->assertStringContainsString('application/', $export->headers->get('Content-Type') ?? '');
        $this->assertDatabaseCount('assets',3);

        $exact=$this->getFiltered($company,'assets',['facility_node_ids'=>[$root],
            'include_descendants'=>false])->assertOk();
        $exact->assertJsonPath('meta.total',0);
        $included=$this->getFiltered($company,'assets',['facility_node_ids'=>[$root],
            'include_descendants'=>true])->assertOk();
        $included->assertJsonPath('meta.total',1);

        $legacy=$this->getFiltered($company,'assets',['status'=>'active'])->assertOk();
        $legacy->assertJsonPath('meta.total',1);
        $empty=$this->getFiltered($company,'assets',[])->assertOk();
        $empty->assertJsonPath('meta.total',3);
    }

    public function test_print_supports_multiple_warehouses_and_disposal_stages_without_losing_tenant_scope(): void
    {
        $company=$this->company('QA warehouse filters');
        $w1=$this->node($company,'Warehouse 1','warehouse');
        $w2=$this->node($company,'Warehouse 2','warehouse');
        $p=$this->person($company,'Warehouse custodian');
        $a=$this->asset($company,'QA-STOCK-A','active',$w1,$p);
        $b=$this->asset($company,'QA-STOCK-B','disposed',$w2,$p);
        foreach ([[$a,'draft'],[$b,'approved']] as [$asset,$status]) {
            DB::table('asset_disposal_cases')->insert([
                'id'=>(string)Str::uuid(),'company_id'=>$company->id,'asset_id'=>$asset,
                'reason'=>'Multiselect QA case','status'=>$status,'created_at'=>now(),'updated_at'=>now(),
            ]);
        }
        $itemId=(string)Str::uuid();
        DB::table('stock_items')->insert([
            'id'=>$itemId,'company_id'=>$company->id,'code'=>'SKU-MULTI',
            'name'=>'QA stock','unit'=>'pcs','minimum_quantity_milli'=>1000,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        foreach ([$w1,$w2] as $warehouse) {
            DB::table('stock_balances')->insert([
                'company_id'=>$company->id,'stock_item_id'=>$itemId,
                'warehouse_node_id'=>$warehouse,'quantity_milli'=>1000,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
        }
        $other=$this->company('Foreign tenant');
        $foreignWarehouse=$this->node($other,'Foreign warehouse','warehouse');
        $this->login($company);
        $this->getFiltered($company,'reports/forms/warehouse-balances',[
            'warehouse_node_ids'=>[$w1,$w2]
        ])->assertOk()->assertJsonPath('data.row_count',2);
        $this->getFiltered($company,'reports/forms/disposal-register',[
            'disposal_statuses'=>['draft','approved']
        ])->assertOk()->assertJsonPath('data.row_count',2);
        $this->getFiltered($company,'reports/forms/disposal-register',[
            'disposal_statuses'=>['submitted']
        ])->assertOk()->assertJsonPath('data.row_count',0);
        $this->getFiltered($company,'reports/forms/warehouse-balances',[
            'warehouse_node_ids'=>[$w1,$foreignWarehouse]
        ])->assertUnprocessable();
    }

    public function test_malformed_cross_tenant_duplicates_and_oversized_queries_are_rejected(): void
    {
        $company=$this->company('Own Tenant');
        $other=$this->company('Foreign Tenant');
        $ours=$this->node($company,'Our Site','building');
        $foreign=$this->node($other,'Foreign Site','building');
        $wrongPerson=$this->person($other,'Foreign Person');
        $this->login($company);
        foreach (['assets','assets/export.xlsx','reports/summary','reports/forms/asset-register'] as $endpoint) {
            $this->getFiltered($company,$endpoint,['facility_node_ids'=>[$foreign]])->assertUnprocessable();
            $this->getFiltered($company,$endpoint,['responsible_person_ids'=>[$wrongPerson]])->assertUnprocessable();
            $this->getFiltered($company,$endpoint,['statuses'=>['active','active']])->assertUnprocessable();
            $this->getFiltered($company,$endpoint,['statuses'=>array_fill(0,31,'active')])->assertUnprocessable();
        }
        $this->getFiltered($company,'assets',['facility_node_ids'=>[$ours]])->assertOk();
    }

    public function test_multi_select_preferences_are_user_and_company_scoped_and_resettable(): void
    {
        $company=$this->company('Preference tenant');
        $other=$this->company('Other preference tenant');
        $facility=$this->node($company,'Office','building');
        $foreign=$this->node($other,'Foreign office','building');
        $person=$this->person($company,'Office custodian');
        $this->login($company);
        $url='/api/v1/companies/'.$company->id.'/preferences/assets';
        $settings=[
            'settings'=>[
                'statuses'=>['active','disposed'],
                'facility_node_ids'=>[$facility],
                'responsible_person_ids'=>[$person],
                'per_page'=>50,'sort_key'=>'name','sort_direction'=>'asc',
                'hidden_columns'=>[],'column_order'=>[],
            ],
        ];
        $this->putJson($url,$settings)->assertOk()
            ->assertJsonPath('data.settings.statuses.1','disposed');
        $this->getJson($url)->assertOk()->assertJsonPath('data.settings.facility_node_ids.0',$facility);
        $settings['settings']['facility_node_ids']=[$foreign];
        $this->putJson($url,$settings)->assertUnprocessable();
        $this->getJson('/api/v1/companies/'.$other->id.'/preferences/assets')->assertNotFound();
        $this->deleteJson($url)->assertNoContent();
        $this->getJson($url)->assertJsonPath('data.settings',null);
    }
}
