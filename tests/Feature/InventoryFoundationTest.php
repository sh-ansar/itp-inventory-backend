<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InventoryFoundationTest extends TestCase {
 use RefreshDatabase;

 public function test_health_is_public_and_assets_are_protected(): void {
  $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('service','itp-inventory-api');
  $this->getJson('/api/v1/companies/'.Str::uuid().'/assets')->assertUnauthorized();
 }

 public function test_browser_login_is_disabled_by_default(): void {
  config()->set('inventory.browser_login_enabled', false);
  $this->postJson('/auth/login',['email'=>'x@example.com','password'=>'abc'])->assertNotFound();
 }

 public function test_company_membership_is_enforced(): void {
  $u=User::factory()->create(['is_active' => true]);
  $company=Company::query()->create(['id'=>(string) Str::uuid(),'name'=>'A']);
  $other=Company::query()->create(['id'=>(string) Str::uuid(),'name'=>'B']);
  $u->companyMemberships()->create(['company_id'=>$company->id,'role_code'=>'inventory_manager']);
  $this->actingAs($u)->getJson('/api/v1/companies/'.$other->id.'/assets')->assertNotFound();
  $this->getJson('/api/v1/companies/'.$company->id.'/assets')->assertOk();
  $this->getJson('/api/v1/companies')->assertOk()->assertJsonPath('0.id',$company->id);
  $this->postJson('/api/v1/companies/'.$company->id.'/assets',[
    'name'=>'Laptop','inventory_number'=>'INV-0001'
  ])->assertCreated()->assertJsonPath('data.inventory_number','INV-0001');
  $this->postJson('/api/v1/companies/'.$company->id.'/assets',[
    'name'=>'Duplicate','inventory_number'=>'INV-0001'
  ])->assertUnprocessable();
 }
}