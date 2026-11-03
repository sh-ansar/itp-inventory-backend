<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReferenceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_location_update_archive_and_tenant_isolation(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $first = Company::create(['id' => (string) Str::uuid(), 'name' => 'One']);
        $second = Company::create(['id' => (string) Str::uuid(), 'name' => 'Two']);
        $user->companyMemberships()->create(['company_id' => $first->id, 'role_code' => 'company_admin']);
        $user->companyMemberships()->create(['company_id' => $second->id, 'role_code' => 'company_admin']);
        $this->actingAs($user);
        $prefix = '/api/v1/companies/'.$first->id;
        $location = $this->postJson($prefix.'/locations', [
            'kind' => 'physical', 'code' => 'A-01', 'name' => 'Workshop',
        ])->assertCreated()->json('id');
        $this->postJson($prefix.'/locations', [
            'kind' => 'physical', 'code' => 'A-02', 'name' => ' WORKSHOP ',
        ])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->putJson($prefix.'/locations/'.$location, [
            'version' => 1, 'name' => 'Workshop East',
        ])->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson($prefix.'/locations/'.$location, [
            'version' => 1, 'name' => 'Stale',
        ])->assertStatus(409);
        $this->postJson('/api/v1/companies/'.$second->id.'/locations/'.$location.'/archive', [
            'version' => 2,
        ])->assertNotFound();
        $this->postJson($prefix.'/locations/'.$location.'/archive', [
            'version' => 2,
        ])->assertOk()->assertJsonPath('data.is_active', false);
        $this->getJson($prefix.'/reference-data?include_archived=1')->assertOk()
            ->assertJsonPath('physical_locations.0.is_active', false);
        $this->getJson($prefix.'/reference-data')->assertOk()
            ->assertJsonCount(0, 'physical_locations');
        $this->postJson($prefix.'/locations/'.$location.'/archive', [
            'version' => 3,
        ])->assertStatus(409);
        $this->putJson($prefix.'/locations/'.$location, [
            'version' => 3, 'name' => 'Archived edit',
        ])->assertStatus(409);
    }

    public function test_category_archives_non_destructively_without_cross_company_access(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $one = Company::create(['id' => (string) Str::uuid(), 'name' => 'One']);
        $two = Company::create(['id' => (string) Str::uuid(), 'name' => 'Two']);
        $user->companyMemberships()->create(['company_id' => $one->id, 'role_code' => 'inventory_manager']);
        $user->companyMemberships()->create(['company_id' => $two->id, 'role_code' => 'viewer']);
        $this->actingAs($user);
        $cat = $this->postJson('/api/v1/companies/'.$one->id.'/categories', [
            'code' => 'MACHINES', 'name' => 'Machines',
        ])->assertCreated()->json('id');
        $this->postJson('/api/v1/companies/'.$two->id.'/categories/'.$cat.'/archive', [
            'version' => 1,
        ])->assertForbidden();
        $asset = $this->postJson('/api/v1/companies/'.$one->id.'/assets', [
            'inventory_number' => 'INV-01', 'name' => 'Existing machinery', 'category_id' => $cat,
        ])->assertCreated()->json('data');
        $this->postJson('/api/v1/companies/'.$one->id.'/categories/'.$cat.'/archive', [
            'version' => 1,
        ])->assertOk()->assertJsonPath('data.is_active', false);
        $this->postJson('/api/v1/companies/'.$one->id.'/assets', [
            'inventory_number' => 'INV-02', 'name' => 'New machinery', 'category_id' => $cat,
        ])->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->putJson('/api/v1/companies/'.$one->id.'/assets/'.$asset['id'], [
            'version' => $asset['version'], 'inventory_number' => $asset['inventory_number'],
            'name' => 'Existing machinery updated', 'category_id' => $cat,
        ])->assertOk();

        $this->getJson('/api/v1/companies/'.$one->id.'/reference-data?include_archived=1')
            ->assertJsonPath('categories.0.is_active', false);
    }
}
