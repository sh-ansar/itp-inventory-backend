<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AssetRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $name = 'ITP Mining'): Company
    {
        return Company::query()->create(['id' => (string) Str::uuid(), 'name' => $name]);
    }

    private function member(Company $company, string $role = 'inventory_manager'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->companyMemberships()->create(['company_id' => $company->id, 'role_code' => $role]);
        return $user;
    }

    private function url(Company $company, string $tail = ''): string
    {
        return '/api/v1/companies/'.$company->id.'/assets'.$tail;
    }

    private function createAsset(Company $company, string $number, string $name = 'Ноутбук'): string
    {
        return $this->postJson($this->url($company), [
            'inventory_number' => $number, 'name' => $name,
        ])->assertCreated()->json('data.id');
    }

    public function test_company_registry_pagination_search_and_sort_are_server_side(): void
    {
        $a = $this->company();
        $b = $this->company('Another company');
        $user = $this->member($a);
        $this->actingAs($user);
        for ($i = 1; $i <= 12; $i++) {
            $this->createAsset($a, sprintf('INV-%03d', $i), 'Ноутбук '.$i);
        }
        $userB = $this->member($b);
        $this->actingAs($userB);
        $this->createAsset($b, 'INV-001', 'Чужой актив');
        $this->actingAs($user);

        $this->getJson($this->url($a).'?sort=inventory_number&direction=desc&per_page=10')
            ->assertOk()->assertJsonPath('meta.total', 12)
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('data.0.inventory_number', 'INV-012');

        $this->getJson($this->url($a).'?page=2&per_page=10')
            ->assertOk()->assertJsonPath('meta.current_page', 2)
            ->assertJsonCount(2, 'data');

        $this->getJson($this->url($a).'?search=INV-012')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.inventory_number', 'INV-012');

        $this->getJson($this->url($a).'?search=Чужой')->assertJsonPath('meta.total', 0);
        $this->getJson($this->url($a).'?sort=company_id')->assertUnprocessable();
        $this->getJson($this->url($a).'?per_page=10000')->assertUnprocessable();
    }

    public function test_wildcards_in_search_are_literal(): void
    {
        $company = $this->company();
        $this->actingAs($this->member($company));
        $this->createAsset($company, 'A_01', 'Ноутбук 1');
        $this->createAsset($company, 'AB01', 'Ноутбук 2');
        $this->getJson($this->url($company).'?search=A_')->assertJsonPath('meta.total', 1);
        $this->getJson($this->url($company).'?search=%25')->assertJsonPath('meta.total', 0);
    }

    public function test_inventory_numbers_are_unique_per_company_and_never_reused_after_archive(): void
    {
        $a = $this->company();
        $b = $this->company('B');
        $this->actingAs($this->member($a, 'company_admin'));
        $id = $this->createAsset($a, 'S-001');
        $this->postJson($this->url($a), ['inventory_number' => 'S-001', 'name' => 'Дубликат'])
            ->assertUnprocessable()->assertJsonValidationErrors(['inventory_number']);
        $this->deleteJson($this->url($a, '/'.$id), [
            'version' => 1, 'reason' => 'Дублирующая позиция',
        ])->assertOk();
        $this->postJson($this->url($a), ['inventory_number' => 'S-001', 'name' => 'Повтор'])
            ->assertUnprocessable()->assertJsonValidationErrors(['inventory_number']);
        $this->actingAs($this->member($b));
        $this->createAsset($b, 'S-001');
    }

    public function test_read_only_role_cannot_write_and_other_company_stays_hidden(): void
    {
        $a = $this->company();
        $b = $this->company('Second');
        $this->actingAs($this->member($a));
        $id = $this->createAsset($a, 'INV-001');
        $this->actingAs($this->member($a, 'viewer'));
        $this->getJson($this->url($a))->assertOk();
        $this->postJson($this->url($a), ['inventory_number' => 'INV-002', 'name' => 'Other'])
            ->assertForbidden();
        $this->putJson($this->url($a, '/'.$id), [
            'inventory_number'=>'INV-001','name'=>'Update','version'=>1,
        ])->assertForbidden();
        $this->deleteJson($this->url($a, '/'.$id), [
            'version'=>1,'reason'=>'Not authorized',
        ])->assertForbidden();
        $this->postJson('/api/v1/companies/'.$a->id.'/categories', ['code'=>'IT','name'=>'Computers'])
            ->assertForbidden();

        $this->getJson($this->url($b))->assertNotFound();
        $this->getJson($this->url($b, '/'.$id))->assertNotFound();
        $this->getJson('/api/v1/companies/'.$b->id.'/reference-data')->assertNotFound();
        $this->getJson($this->url($b, '/'.$id.'/events'))->assertNotFound();
    }

    public function test_cross_company_category_or_wrong_location_kind_is_rejected(): void
    {
        $a = $this->company();
        $b = $this->company('Separate');
        $this->actingAs($this->member($b));
        $foreignCategory = $this->postJson('/api/v1/companies/'.$b->id.'/categories', [
            'code'=>'PC','name'=>'Computers',
        ])->assertCreated()->json('id');
        $foreignLocation = $this->postJson('/api/v1/companies/'.$b->id.'/locations', [
            'kind'=>'physical','code'=>'OFFICE','name'=>'Office',
        ])->assertCreated()->json('id');

        $this->actingAs($this->member($a));
        $this->postJson($this->url($a), [
            'name'=>'Wrong company', 'inventory_number'=>'X1','category_id'=>$foreignCategory,
        ])->assertUnprocessable()->assertJsonValidationErrors(['category_id']);
        $this->postJson($this->url($a), [
            'name'=>'Wrong company', 'inventory_number'=>'X2','accounting_location_id'=>$foreignLocation,
        ])->assertUnprocessable()->assertJsonValidationErrors(['accounting_location_id']);

        $category = $this->postJson('/api/v1/companies/'.$a->id.'/categories', [
            'code'=>'PC','name'=>'Компьютеры',
        ])->assertCreated()->json('id');
        $accounting = $this->postJson('/api/v1/companies/'.$a->id.'/locations', [
            'kind'=>'accounting','code'=>'BAL-1','name'=>'На балансе',
        ])->assertCreated()->json('id');
        $physical = $this->postJson('/api/v1/companies/'.$a->id.'/locations', [
            'kind'=>'physical','code'=>'OFF-1','name'=>'Офис',
        ])->assertCreated()->json('id');
        $this->getJson('/api/v1/companies/'.$a->id.'/reference-data')
            ->assertJsonPath('categories.0.id',$category)
            ->assertJsonPath('accounting_locations.0.id',$accounting)
            ->assertJsonPath('physical_locations.0.id',$physical);
        $this->postJson($this->url($a), [
            'name'=>'Верный актив', 'inventory_number'=>'X3',
            'category_id'=>$category,'accounting_location_id'=>$accounting,
            'physical_location_id'=>$physical,
        ])->assertCreated()->assertJsonPath('data.category_name','Компьютеры');
    }

    public function test_optimistic_lock_and_event_history_and_mass_assignment_protection(): void
    {
        $company = $this->company();
        $this->actingAs($this->member($company));
        $id = $this->createAsset($company, 'AS-1');
        $this->getJson($this->url($company, '/'.$id))
            ->assertOk()->assertJsonPath('data.version',1);
        $this->putJson($this->url($company, '/'.$id), [
            'version'=>1,'name'=>'Ноутбук обновлён', 'inventory_number'=>'AS-1',
            'serial_number'=>'SN-ABC', 'status'=>'disposed',
            'company_id'=>(string) Str::uuid(),
        ])->assertOk()->assertJsonPath('data.version',2)
            ->assertJsonPath('data.status','active')
            ->assertJsonPath('data.name','Ноутбук обновлён');
        $this->putJson($this->url($company, '/'.$id), [
            'version'=>1,'name'=>'Старые данные','inventory_number'=>'AS-1',
        ])->assertStatus(409);
        $this->putJson($this->url($company, '/'.$id), [
            'version'=>2,'name'=>'Ноутбук обновлён','inventory_number'=>'AS-1','serial_number'=>'SN-ABC',
        ])->assertOk()->assertJsonPath('data.version',2);
        $this->getJson($this->url($company, '/'.$id.'/events'))
            ->assertOk()->assertJsonPath('meta.total',2);
        $this->assertSame(2, Asset::query()->findOrFail($id)->version);
        $this->assertDatabaseCount('asset_events', 2);
    }

    public function test_archival_requires_admin_reason_and_version_and_preserves_history(): void
    {
        $company = $this->company();
        $this->actingAs($this->member($company));
        $id = $this->createAsset($company, 'AS-1');
        $this->deleteJson($this->url($company,'/'.$id), [
            'version'=>1,'reason'=>'Missing permission',
        ])->assertForbidden();
        $this->actingAs($this->member($company,'company_admin'));
        $this->deleteJson($this->url($company,'/'.$id), [
            'version'=>1,'reason'=>'x',
        ])->assertUnprocessable();
        $this->deleteJson($this->url($company,'/'.$id), [
            'version'=>2,'reason'=>'Ошибка в реестре',
        ])->assertStatus(409);
        $this->deleteJson($this->url($company,'/'.$id), [
            'version'=>1,'reason'=>'Ошибка в реестре',
        ])->assertOk();
        $this->getJson($this->url($company,'/'.$id))->assertNotFound();
        $this->getJson($this->url($company))->assertJsonPath('meta.total',0);
        $this->getJson($this->url($company,'/'.$id.'/events'))
            ->assertOk()->assertJsonPath('meta.total',2)
            ->assertJsonPath('data.0.event_type','archived');
        $this->assertSoftDeleted('assets',['id'=>$id]);
    }

    public function test_inactive_or_anonymous_accounts_have_no_access(): void
    {
        $company = $this->company();
        $this->getJson($this->url($company))->assertUnauthorized();
        $this->getJson('/api/v1/companies')->assertUnauthorized();
        $this->actingAs($this->member($company));
        $this->getJson($this->url($company))->assertOk();
        $inactive = $this->member($company);
        $inactive->update(['is_active'=>false]);
        $this->actingAs($inactive)->getJson($this->url($company))->assertUnauthorized();
    }
}