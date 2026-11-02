<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WarehouseUnitSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $name): Company
    {
        return Company::create(['id'=>(string)Str::uuid(),'name'=>$name]);
    }

    private function warehouse(Company $company, string $name): string
    {
        $id=(string)Str::uuid();
        DB::table('facility_nodes')->insert([
            'id'=>$id,'company_id'=>$company->id,'code'=>Str::random(12),
            'name'=>$name,'node_type'=>'warehouse',
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        return $id;
    }

    private function balance(Company $company, string $warehouse, string $code,
        string $unit, int $quantityMilli): void
    {
        $id=(string)Str::uuid();
        DB::table('stock_items')->insert([
            'id'=>$id,'company_id'=>$company->id,
            'code'=>$code,'name'=>$code.' product','unit'=>$unit,
            'minimum_quantity_milli'=>0,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('stock_balances')->insert([
            'company_id'=>$company->id,
            'stock_item_id'=>$id,'warehouse_node_id'=>$warehouse,
            'quantity_milli'=>$quantityMilli,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    public function test_mixed_units_are_never_summed_into_one_accounting_quantity(): void
    {
        $a=$this->company('Warehouse accounting QA');
        $b=$this->company('Foreign warehouse accounting');
        $w1=$this->warehouse($a,'Warehouse A');
        $w2=$this->warehouse($a,'Warehouse B');
        $foreign=$this->warehouse($b,'Secret warehouse');
        $this->balance($a,$w1,'SKU-PC-A','pcs',1500);
        $this->balance($a,$w1,'SKU-PC-B','pcs',2500);
        $this->balance($a,$w1,'SKU-KG','kg',7500);
        $this->balance($a,$w2,'SKU-L','l',2000);
        $this->balance($b,$foreign,'SKU-FOREIGN','kg',999000);
        $user=User::factory()->create(['is_active'=>true]);
        $user->companyMemberships()->create(['company_id'=>$a->id,'role_code'=>'viewer']);
        $this->actingAs($user);
        $root='/api/v1/companies/'.$a->id.'/reports/summary';

        $this->getJson($root)->assertOk()
            ->assertJsonPath('data.warehouses.total_items',4)
            ->assertJsonCount(2,'data.warehouses.by_warehouse')
            ->assertJsonPath('data.warehouses.by_warehouse.0.id',$w1)
            ->assertJsonPath('data.warehouses.by_warehouse.0.items',3)
            ->assertJsonPath('data.warehouses.by_warehouse.0.quantity_milli',null)
            ->assertJsonPath('data.warehouses.by_warehouse.0.unit',null)
            ->assertJsonPath('data.warehouses.by_warehouse.0.unit_quantities.0.unit','kg')
            ->assertJsonPath('data.warehouses.by_warehouse.0.unit_quantities.0.quantity_milli',7500)
            ->assertJsonPath('data.warehouses.by_warehouse.0.unit_quantities.1.unit','pcs')
            ->assertJsonPath('data.warehouses.by_warehouse.0.unit_quantities.1.items',2)
            ->assertJsonPath('data.warehouses.by_warehouse.0.unit_quantities.1.quantity_milli',4000)
            ->assertJsonPath('data.warehouses.by_warehouse.1.id',$w2)
            ->assertJsonPath('data.warehouses.by_warehouse.1.unit','l')
            ->assertJsonPath('data.warehouses.by_warehouse.1.quantity_milli',2000);

        $csv=$this->get($root.'.csv')->assertOk();
        $text=$csv->streamedContent();
        $this->assertStringContainsString('Warehouse;Items;Unit;Quantity',$text);
        $this->assertStringContainsString('kg;7.500',$text);
        $this->assertStringContainsString('pcs;4.000',$text);
        $this->assertStringContainsString('l;2.000',$text);
        $this->assertStringNotContainsString('Secret warehouse',$text);
        $this->assertStringNotContainsString('999.000',$text);
        $this->getJson('/api/v1/companies/'.$b->id.'/reports/summary')->assertNotFound();
    }
}
