<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class InventorySessionTest extends TestCase {
 use RefreshDatabase;

 public function test_login_me_and_logout_with_own_credentials(): void {
  config()->set('inventory.browser_login_enabled', true);
  $user = User::factory()->create([
   'is_active'=>true,
   'email'=>'inventory.qa@example.test',
   'password'=>Hash::make('example-pass-93'),
  ]);
  $this->postJson('/auth/login',['email'=>'inventory.qa@example.test','password'=>'example-pass-93'])
   ->assertOk()->assertJsonPath('id',$user->id);
  $this->getJson('/auth/me')->assertOk()->assertJsonPath('id',$user->id);
  $this->postJson('/auth/logout')->assertOk();
  $this->getJson('/auth/me')->assertUnauthorized();
 }

 public function test_inactive_account_cannot_sign_in(): void {
  config()->set('inventory.browser_login_enabled', true);
  User::factory()->create([
   'is_active'=>false,
   'email'=>'blocked.qa@example.test',
   'password'=>Hash::make('example-pass-93'),
  ]);
  $this->postJson('/auth/login',['email'=>'blocked.qa@example.test','password'=>'example-pass-93'])
   ->assertUnprocessable();
 }

 public function test_xsrf_cookie_uses_inventory_specific_name(): void {
  $response=$this->get('/sanctum/csrf-cookie');
  $response->assertNoContent();
  $names = array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies());
  $this->assertContains('INVENTORY-XSRF-TOKEN', $names);
 }
}