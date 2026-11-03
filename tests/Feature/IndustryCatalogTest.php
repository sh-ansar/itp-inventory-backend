<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class IndustryCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $name): Company
    {
        return Company::create(['id' => (string) Str::uuid(), 'name' => $name]);
    }

    private function join(User $user, Company $company, string $role): void
    {
        $user->companyMemberships()->create(['company_id' => $company->id, 'role_code' => $role]);
    }

    public function test_four_catalogs_are_tenant_scoped_with_parent_and_no_duplicates(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $one = $this->company('One');
        $two = $this->company('Two');
        $this->join($admin, $one, 'company_admin');
        $this->join($admin, $two, 'company_admin');
        $this->actingAs($admin);
        $a = '/api/v1/companies/'.$one->id.'/industry-catalogs/';
        $b = '/api/v1/companies/'.$two->id.'/industry-catalogs/';

        $manufacturer = $this->postJson($a.'manufacturer', [
            'code' => 'dell', 'name' => 'Dell',
        ])->assertCreated()->json('data');
        $this->postJson($a.'manufacturer', [
            'code' => 'dell2', 'name' => '  DELL  ',
        ])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson($b.'manufacturer', [
            'code' => 'dell', 'name' => 'Dell',
        ])->assertCreated();
        $this->getJson($a.'manufacturer')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($b.'manufacturer')->assertOk()->assertJsonCount(1, 'data');

        $model = $this->postJson($a.'model', [
            'code' => 'precision', 'name' => 'Precision 7680', 'parent_id' => $manufacturer['id'],
        ])->assertCreated()->json('data');
        $this->postJson($b.'model', [
            'code' => 'model2', 'name' => 'Other', 'parent_id' => $manufacturer['id'],
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->postJson($a.'department', [
            'code' => 'it', 'name' => 'IT Department',
        ])->assertCreated();
        $this->putJson($a.'manufacturer/'.$manufacturer['id'], [
            'version' => 1, 'name' => 'DELL Technologies',
        ])->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson($a.'manufacturer/'.$manufacturer['id'], [
            'version' => 1, 'name' => 'Stale',
        ])->assertStatus(409);
        $this->postJson($a.'manufacturer/'.$manufacturer['id'].'/archive', [
            'version' => 2,
        ])->assertStatus(409);
        $this->postJson($a.'model/'.$model['id'].'/archive', [
            'version' => 1,
        ])->assertOk()->assertJsonPath('data.is_active', false);
        $this->postJson($a.'manufacturer/'.$manufacturer['id'].'/archive', [
            'version' => 2,
        ])->assertOk()->assertJsonPath('data.is_active', false);
        $this->getJson($a.'manufacturer')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($a.'manufacturer?include_archived=1')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_custom_units_have_stock_precision_and_protected_archive(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $one = $this->company('Company');
        $this->join($user, $one, 'inventory_manager');
        $this->actingAs($user);
        $base = '/api/v1/companies/'.$one->id;
        $this->getJson($base.'/industry-catalogs/unit')->assertOk()->assertJsonCount(5, 'data');

        $unit = $this->postJson($base.'/industry-catalogs/unit', [
            'code' => 'roll', 'name' => 'Cable rolls', 'precision' => 0,
        ])->assertCreated()->json('data');
        $this->postJson($base.'/industry-catalogs/unit', [
            'code' => 'kg', 'name' => 'Kilograms duplicate', 'precision' => 3,
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson($base.'/stock-items', [
            'code' => 'CAB-01', 'name' => 'Cable rolls', 'unit' => 'roll',
            'minimum_quantity' => '1.500',
        ])->assertUnprocessable()->assertJsonValidationErrors('minimum_quantity');
        $this->postJson($base.'/stock-items', [
            'code' => 'CAB-01', 'name' => 'Cable rolls', 'unit' => 'roll',
            'minimum_quantity' => '1',
        ])->assertCreated();
        $this->postJson($base.'/industry-catalogs/unit/'.$unit['id'].'/archive', [
            'version' => 1,
        ])->assertStatus(409);
        $this->postJson($base.'/stock-items', [
            'code' => 'UNKNOWN', 'name' => 'Invalid unit', 'unit' => 'other',
        ])->assertUnprocessable()->assertJsonValidationErrors('unit');
    }

    public function test_viewers_cannot_mutate_and_cross_tenant_entries_are_not_exposed(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $one = $this->company('One');
        $two = $this->company('Two');
        $this->join($user, $one, 'company_admin');
        $this->join($user, $two, 'viewer');
        $this->actingAs($user);
        $a = '/api/v1/companies/'.$one->id.'/industry-catalogs/department';
        $b = '/api/v1/companies/'.$two->id.'/industry-catalogs/department';
        $row = $this->postJson($a, ['code' => 'hr', 'name' => 'Human resources'])
            ->assertCreated()->json('data');
        $this->getJson($b)->assertOk()->assertJsonCount(0, 'data');
        $this->putJson($b.'/'.$row['id'], ['version' => 1, 'name' => 'Other'])
            ->assertForbidden();
        $this->postJson($b, ['code' => 'sales', 'name' => 'Sales'])
            ->assertForbidden();
        $this->postJson($a.'/'.$row['id'].'/archive', ['version' => 1])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->putJson($a.'/'.$row['id'], ['version' => 2, 'name' => 'Revived'])
            ->assertStatus(409);
    }
}
