<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DetailedPrintReportsTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $name): Company
    {
        return Company::create(['id'=>(string)Str::uuid(),'name'=>$name]);
    }

    private function actor(Company $company): User
    {
        $user=User::factory()->create(['is_active'=>true]);
        $user->companyMemberships()->create(['company_id'=>$company->id,'role_code'=>'viewer']);
        return $user;
    }

    private function url(Company $company, string $kind): string
    {
        return '/api/v1/companies/'.$company->id.'/reports/forms/'.$kind;
    }

    private function fixture(Company $company): array
    {
        $now=now();
        $person=(string)Str::uuid();
        DB::table('responsible_people')->insert([
            'id'=>$person,'company_id'=>$company->id,'code'=>'P-01','name'=>'QA Custodian',
            'created_at'=>$now,'updated_at'=>$now,
        ]);
        $nodes=[];
        foreach (['building'=>'Main Building','room'=>'Storage Room','warehouse'=>'North Warehouse'] as $type=>$name) {
            $id=(string)Str::uuid();
            DB::table('facility_nodes')->insert([
                'id'=>$id,'company_id'=>$company->id,'code'=>'QA-'.strtoupper($type),
                'name'=>$name,'node_type'=>$type,'created_at'=>$now,'updated_at'=>$now,
            ]);
            $nodes[$type]=$id;
        }
        $asset=(string)Str::uuid();
        DB::table('assets')->insert([
            'id'=>$asset,'public_id'=>(string)Str::uuid(),'company_id'=>$company->id,
            'inventory_number'=>'PR-001','name'=>'Desktop workstation',
            'status'=>'active','facility_node_id'=>$nodes['building'],
            'responsible_person_id'=>$person,'created_at'=>$now,'updated_at'=>$now,
        ]);
        $session=(string)Str::uuid();
        DB::table('inventory_sessions')->insert([
            'id'=>$session,'company_id'=>$company->id,'name'=>'Quarterly QA count',
            'status'=>'in_progress','total_items'=>1,'counted_items'=>1,'discrepancies'=>1,
            'created_by_name'=>'QA Inventory Operator','created_at'=>$now,'updated_at'=>$now,
        ]);
        DB::table('inventory_session_items')->insert([
            'id'=>(string)Str::uuid(),'company_id'=>$company->id,'session_id'=>$session,
            'asset_id'=>$asset,'inventory_number'=>'PR-001','name'=>'Desktop workstation',
            'expected_facility_node_id'=>$nodes['building'],
            'expected_facility_node_name'=>'Main Building',
            'actual_facility_node_id'=>$nodes['room'],
            'actual_facility_node_name'=>'Storage Room',
            'is_counted'=>true,'created_at'=>$now,'updated_at'=>$now,
        ]);
        DB::table('asset_disposal_cases')->insert([
            'id'=>(string)Str::uuid(),'company_id'=>$company->id,
            'asset_id'=>$asset,'reason'=>'Hardware obsolete','status'=>'draft',
            'created_at'=>$now,'updated_at'=>$now,
        ]);
        $stockId=(string)Str::uuid();
        DB::table('stock_items')->insert([
            'id'=>$stockId,'company_id'=>$company->id,'code'=>'SKU-QA',
            'name'=>'QA Toner','unit'=>'pcs','minimum_quantity_milli'=>2000,
            'created_at'=>$now,'updated_at'=>$now,
        ]);
        DB::table('stock_balances')->insert([
            'company_id'=>$company->id,'stock_item_id'=>$stockId,
            'warehouse_node_id'=>$nodes['warehouse'],'quantity_milli'=>1250,
            'created_at'=>$now,'updated_at'=>$now,
        ]);

        return compact('asset','person','nodes','session');
    }

    public function test_six_read_only_print_forms_use_real_company_data(): void
    {
        $a=$this->tenant('Inventory QA - Mining');
        $ids=$this->fixture($a);
        $this->actingAs($this->actor($a));
        foreach (['asset-register','by-facility','by-custodian'] as $kind) {
            $this->getJson($this->url($a,$kind))->assertOk()
                ->assertJsonPath('data.kind',$kind)
                ->assertJsonPath('data.row_count',1)
                ->assertJsonPath('data.rows.0.inventory_number','PR-001')
                ->assertJsonPath('data.rows.0.responsible_person_name','QA Custodian');
        }
        $this->getJson($this->url($a,'warehouse-balances'))->assertOk()
            ->assertJsonPath('data.rows.0.warehouse_name','North Warehouse')
            ->assertJsonPath('data.rows.0.quantity_milli',1250);
        $this->getJson($this->url($a,'stocktake-act').'?session_id='.$ids['session'])->assertOk()
            ->assertJsonPath('data.context.session_status','in_progress')
            ->assertJsonPath('data.rows.0.is_discrepancy',true)
            ->assertJsonPath('data.rows.0.expected_name','Main Building')
            ->assertJsonPath('data.rows.0.actual_name','Storage Room');
        $this->getJson($this->url($a,'disposal-register'))->assertOk()
            ->assertJsonPath('data.rows.0.case_status','draft')
            ->assertJsonPath('data.rows.0.reason','Hardware obsolete');
        $this->assertDatabaseCount('assets',1);
        $this->assertDatabaseCount('stock_balances',1);
    }

    public function test_filters_never_cross_company_and_invalid_references_fail(): void
    {
        $a=$this->tenant('Tenant A');
        $b=$this->tenant('Tenant B');
        $aIds=$this->fixture($a);
        $bIds=$this->fixture($b);
        $this->actingAs($this->actor($a));
        $base=$this->url($a,'asset-register');
        $this->getJson($base.'?facility_node_id='.$aIds['nodes']['building'])
            ->assertOk()->assertJsonPath('data.row_count',1);
        $this->getJson($base.'?facility_node_id='.$bIds['nodes']['building'])
            ->assertUnprocessable();
        $this->getJson($base.'?responsible_person_id='.$bIds['person'])
            ->assertUnprocessable();
        $this->getJson($base.'?search=not-matching')->assertOk()
            ->assertJsonPath('data.row_count',0);
        $this->getJson($this->url($a,'stocktake-act'))->assertUnprocessable();
        $this->getJson($this->url($a,'stocktake-act').'?session_id='.$bIds['session'])
            ->assertUnprocessable();
        $this->getJson($this->url($a,'warehouse-balances').'?warehouse_node_id='.$bIds['nodes']['warehouse'])
            ->assertUnprocessable();
        $this->getJson($this->url($a,'unknown-kind'))->assertNotFound();
        $this->getJson($this->url($b,'asset-register'))->assertNotFound();
        $this->actingAs($this->actor($b));
        $this->getJson($this->url($b,'asset-register'))->assertOk()
            ->assertJsonPath('data.row_count',1);
    }

    public function test_archive_status_and_disposal_filters_are_distinct(): void
    {
        $company=$this->tenant('QA Cases');
        $ids=$this->fixture($company);
        $this->actingAs($this->actor($company));
        $this->getJson($this->url($company,'asset-register').'?status=disposed')
            ->assertOk()->assertJsonPath('data.row_count',0);
        $this->getJson($this->url($company,'disposal-register').'?disposal_status=draft')
            ->assertOk()->assertJsonPath('data.row_count',1);
        $this->getJson($this->url($company,'disposal-register').'?disposal_status=approved')
            ->assertOk()->assertJsonPath('data.row_count',0);
        $this->getJson($this->url($company,'disposal-register').'?status=active')
            ->assertOk()->assertJsonPath('data.row_count',1);
        $this->getJson($this->url($company,'warehouse-balances').'?warehouse_node_id='.$ids['nodes']['warehouse'])
            ->assertOk()->assertJsonPath('data.row_count',1);
        $this->getJson($this->url($company,'warehouse-balances').'?warehouse_node_id='.$ids['nodes']['building'])
            ->assertUnprocessable();
        DB::table('assets')->where('id',$ids['asset'])->update(['deleted_at'=>now()]);
        $this->getJson($this->url($company,'asset-register'))->assertOk()
            ->assertJsonPath('data.row_count',0);
        // Disposal history remains available even when an asset has been archived.
        $this->getJson($this->url($company,'disposal-register'))->assertOk()
            ->assertJsonPath('data.row_count',1);
    }

    public function test_large_print_register_rejects_incomplete_output_instead_of_truncating(): void
    {
        $company=$this->tenant('Large QA');
        $this->actingAs($this->actor($company));
        $now=now();
        for($batch=0;$batch<5;$batch++){
            $rows=[];
            for($i=0;$i<401;$i++){
                $n=$batch*401+$i;
                $rows[]=[
                    'id'=>(string)Str::uuid(),'public_id'=>(string)Str::uuid(),
                    'company_id'=>$company->id,'inventory_number'=>'BULK-'.str_pad((string)$n,5,'0',STR_PAD_LEFT),
                    'name'=>'Inventory QA asset','status'=>'active',
                    'created_at'=>$now,'updated_at'=>$now,
                ];
            }
            DB::table('assets')->insert($rows);
        }
        $this->getJson($this->url($company,'asset-register'))->assertStatus(413);
    }

    public function test_unauthenticated_user_does_not_receive_print_data(): void
    {
        $company=$this->tenant('Private Company');
        $this->fixture($company);
        $this->getJson($this->url($company,'asset-register'))->assertUnauthorized();
    }
}
