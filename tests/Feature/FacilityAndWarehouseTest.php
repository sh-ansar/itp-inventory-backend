<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\StockBalance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FacilityAndWarehouseTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $name): Company
    {
        return Company::query()->create(['id' => (string) Str::uuid(), 'name' => $name]);
    }

    private function actor(Company $company, string $role = 'company_admin'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->companyMemberships()->create(['company_id' => $company->id, 'role_code' => $role]);

        return $user;
    }

    private function url(Company $company, string $suffix): string
    {
        return '/api/v1/companies/'.$company->id.'/'.$suffix;
    }

    private function node(
        Company $company, string $type, string $code, ?string $parent = null, array $extra = []
    ): array {
        return $this->postJson($this->url($company, 'facility-nodes'), [
            'name' => 'QA '.$code, 'code' => $code, 'node_type' => $type, 'parent_id' => $parent, ...$extra,
        ])->assertCreated()->json('data');
    }

    private function person(Company $company, string $code = 'QA-EMP'): array
    {
        return $this->postJson($this->url($company, 'responsible-people'), [
            'code' => $code, 'name' => 'QA Employee', 'position' => 'Technician',
            'email' => 'qa-person@example.test',
        ])->assertCreated()->json('data');
    }

    private function asset(Company $company, string $number, ?string $facility = null, ?string $responsible = null): array
    {
        return $this->postJson($this->url($company, 'assets'), [
            'inventory_number' => $number, 'name' => 'QA computer '.$number,
            'facility_node_id' => $facility, 'responsible_person_id' => $responsible,
        ])->assertCreated()->json('data');
    }

    public function test_nested_facilities_support_drilldown_addresses_and_manual_responsible_people(): void
    {
        $company = $this->company('Mining');
        $this->actingAs($this->actor($company));
        $person = $this->person($company);
        $site = $this->node($company, 'site', 'SITE-A', null, [
            'address' => 'Astana, Test Street 4',
            'responsible_person_id' => $person['id'],
        ]);
        $building = $this->node($company, 'building', 'BUILDING-A', $site['id']);
        $floor = $this->node($company, 'floor', 'FLOOR-1', $building['id'], ['floor_number' => 1]);
        $room = $this->node($company, 'room', 'ROOM-101', $floor['id']);
        $asset = $this->asset($company, 'INV-COMPUTER-001', $room['id'], $person['id']);
        $this->assertSame($room['id'], $asset['facility_node_id']);
        $this->assertSame($person['id'], $asset['responsible_person_id']);
        $this->assertSame('QA Employee', $asset['responsible_person_name']);
        $response = $this->getJson($this->url($company, 'facility-nodes'))->assertOk()
            ->assertJsonPath('total', 4);
        $actualRoom = collect($response->json('data'))->firstWhere('id', $room['id']);
        $this->assertSame($floor['id'], $actualRoom['parent_id']);
        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($site['id'], $ids);
        $this->assertContains($room['id'], $ids);
        $this->getJson($this->url($company, 'facility-nodes/'.$site['id']))
            ->assertOk()->assertJsonPath('data.direct_asset_count', 0);
        $this->getJson($this->url($company, 'responsible-people'))
            ->assertOk()->assertJsonPath('total', 1);
        $this->assertDatabaseCount('facility_node_events', 4);

        // Editing a person requires an optimistic version.
        $this->putJson($this->url($company, 'responsible-people/'.$person['id']), [
            'code' => 'QA-EMP', 'name' => 'QA Employee Updated', 'position' => 'Technician',
            'is_active' => true, 'version' => 1,
        ])->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson($this->url($company, 'responsible-people/'.$person['id']), [
            'code' => 'QA-EMP', 'name' => 'Outdated', 'version' => 1,
        ])->assertStatus(409);
        $this->putJson($this->url($company, 'responsible-people/'.$person['id']), [
            'code' => 'QA-EMP', 'name' => 'Inactive', 'is_active' => false, 'version' => 2,
        ])->assertUnprocessable()->assertJsonValidationErrors('is_active');
    }

    public function test_no_cycles_invalid_type_or_cross_tenant_parent_or_assignment(): void
    {
        $c = $this->company('Mining');
        $other = $this->company('Solutions');
        $this->actingAs($this->actor($c));
        $site = $this->node($c, 'site', 'ROOT');
        $building = $this->node($c, 'building', 'BUILDING', $site['id']);
        $floor = $this->node($c, 'floor', 'FLOOR', $building['id']);
        $room = $this->node($c, 'room', 'ROOM', $floor['id']);
        $person = $this->person($c);
        $root = $this->url($c, 'facility-nodes');
        $this->postJson($root, [
            'code' => 'BAD', 'name' => 'Bad root room', 'node_type' => 'room',
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->postJson($root, [
            'code' => 'BAD2', 'name' => 'Bad nested site', 'node_type' => 'site',
            'parent_id' => $room['id'],
        ])->assertUnprocessable()->assertJsonValidationErrors('node_type');
        $this->putJson($root.'/'.$building['id'], [
            'code' => 'BUILDING', 'name' => 'QA BUILDING', 'node_type' => 'building',
            'parent_id' => $room['id'], 'version' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        $this->actingAs($this->actor($other));
        $foreign = $this->node($other, 'site', 'FOREIGN');
        $this->actingAs($this->actor($c));
        $this->postJson($root, [
            'code' => 'WRONG', 'name' => 'Foreign parent', 'node_type' => 'building',
            'parent_id' => $foreign['id'],
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->postJson($this->url($c, 'assets'), [
            'name' => 'Foreign asset', 'inventory_number' => 'INV-FOREIGN',
            'facility_node_id' => $foreign['id'],
        ])->assertUnprocessable()->assertJsonValidationErrors('facility_node_id');
        $this->postJson($this->url($c, 'assets'), [
            'name' => 'Foreign person', 'inventory_number' => 'INV-PERSON-FOREIGN',
            'responsible_person_id' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('responsible_person_id');

        $this->putJson($root.'/'.$building['id'], [
            'code' => 'BUILDING', 'name' => 'Renamed', 'node_type' => 'building',
            'parent_id' => $site['id'], 'version' => 1,
        ])->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson($root.'/'.$building['id'], [
            'code' => 'BUILDING', 'name' => 'Outdated', 'node_type' => 'building',
            'parent_id' => $site['id'], 'version' => 1,
        ])->assertStatus(409);
        $this->getJson($root.'/'.$building['id'].'/events')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_facility_count_uses_actual_room_without_legacy_flat_locations(): void
    {
        $company = $this->company('Mining');
        $this->actingAs($this->actor($company));
        $site = $this->node($company, 'site', 'ROOT');
        $room = $this->node($company, 'building', 'BUILDING', $site['id']);
        $other = $this->node($company, 'warehouse', 'WAREHOUSE', $site['id']);
        $this->asset($company, 'MAP-001', $room['id']);
        $session = $this->postJson($this->url($company, 'inventory-sessions'), [
            'name' => 'Room verification', 'facility_node_id' => $site['id'],
        ])->assertCreated()->json('data');
        $item = $this->getJson($this->url($company, 'inventory-sessions/'.$session['id'].'/items'))
            ->assertOk()->json('data.0');
        $url = $this->url($company, 'inventory-sessions/'.$session['id'].'/items/'.$item['id']);

        $this->putJson($url, [
            'version' => 1, 'actual_facility_node_id' => $other['id'],
        ])->assertOk()
            ->assertJsonPath('data.session.counted_items', 1)
            ->assertJsonPath('data.session.discrepancies', 1)
            ->assertJsonPath('data.item.is_discrepancy', true)
            ->assertJsonPath('data.item.actual_facility_node_name', 'QA WAREHOUSE');
        $this->putJson($url, [
            'version' => 2, 'actual_facility_node_id' => $room['id'],
            'reason' => 'Verified room location with custodian',
        ])->assertOk()
            ->assertJsonPath('data.session.discrepancies', 0)
            ->assertJsonPath('data.item.is_discrepancy', false);

        $otherAsset = $this->asset($company, 'MAP-002', $room['id']);
        $this->postJson($this->url($company, 'inventory-sessions/'.$session['id'].'/scan'), [
            'public_id' => $otherAsset['public_id'], 'actual_facility_node_id' => $room['id'],
        ])->assertNotFound();
        $this->getJson($this->url($company, 'inventory-sessions/'.$session['id'].'/events'))
            ->assertJsonPath('meta.total', 3);
        $this->assertDatabaseCount('asset_locations', 0);
    }

    public function test_company_access_and_readonly_viewer_for_map_and_people(): void
    {
        $c = $this->company('Mining');
        $other = $this->company('Solutions');
        $this->actingAs($this->actor($c));
        $site = $this->node($c, 'site', 'SITE');
        $this->person($c);
        $this->actingAs($this->actor($c, 'viewer'));
        $this->getJson($this->url($c, 'facility-nodes'))->assertOk();
        $this->getJson($this->url($c, 'responsible-people'))->assertOk();
        $this->postJson($this->url($c, 'facility-nodes'), [
            'code' => 'NO', 'name' => 'Not allowed', 'node_type' => 'site',
        ])->assertForbidden();
        $this->postJson($this->url($c, 'responsible-people'), [
            'code' => 'NO', 'name' => 'Not allowed',
        ])->assertForbidden();
        $this->actingAs($this->actor($other));
        $this->getJson($this->url($c, 'facility-nodes'))->assertNotFound();
        $this->getJson($this->url($c, 'responsible-people'))->assertNotFound();
        $this->getJson($this->url($c, 'facility-nodes/'.$site['id']))->assertNotFound();
        $this->assertDatabaseCount('facility_nodes', 1);
    }

    public function test_equipment_type_presets_are_idempotent_unique_and_have_safe_icon_upload(): void
    {
        Storage::fake('local');
        $c = $this->company('Mining');
        $this->actingAs($this->actor($c));
        $url = $this->url($c, 'equipment-presets/initialize');
        $this->postJson($url)->assertOk()->assertJsonPath('added', 12);
        $this->postJson($url)->assertOk()->assertJsonPath('added', 0);
        $this->assertDatabaseCount('asset_categories', 12);
        $this->postJson($this->url($c, 'categories'), [
            'code' => 'custom-1', 'name' => 'ноУтбуки', 'icon_key' => 'laptop',
        ])->assertUnprocessable()->assertJsonValidationErrors('name');
        $category = $this->postJson($this->url($c, 'categories'), [
            'code' => 'custom-1', 'name' => 'Custom specialty device', 'icon_key' => 'camera',
        ])->assertCreated()->json();
        $this->postJson($this->url($c, 'categories/'.$category['id'].'/icon'), [
            'icon' => UploadedFile::fake()->image('test.png', 128, 128),
            'version' => 1,
        ])->assertOk()->assertJsonPath('data.version', 2);
        $this->get($this->url($c, 'categories/'.$category['id'].'/icon'))->assertOk()
            ->assertHeader('Content-Type', 'image/png');
        $this->putJson($this->url($c, 'categories/'.$category['id']), [
            'name' => 'Custom specialty device updated', 'icon_key' => 'camera', 'version' => 1,
        ])->assertStatus(409);
        $this->putJson($this->url($c, 'categories/'.$category['id']), [
            'name' => 'Custom specialty device updated', 'icon_key' => 'camera', 'version' => 2,
        ])->assertOk()->assertJsonPath('data.version', 3);
        $this->getJson($this->url($c, 'reference-data'))
            ->assertOk()->assertJsonCount(13, 'categories');
    }

    public function test_warehouse_receipt_transfer_issue_and_tenant_stock_guards(): void
    {
        $c = $this->company('Mining');
        $other = $this->company('Solutions');
        $this->actingAs($this->actor($c));
        $a = $this->node($c, 'warehouse', 'WH-A');
        $b = $this->node($c, 'warehouse', 'WH-B');
        $sku = $this->postJson($this->url($c, 'stock-items'), [
            'code' => 'CABLE-CAT6', 'name' => 'LAN cable, meters', 'unit' => 'm', 'minimum_quantity' => '4.500',
        ])->assertCreated()->json('data');
        $this->assertSame(4500, $sku['minimum_quantity_milli']);
        $base = $this->url($c, 'stock-transactions');
        $this->postJson($base, [
            'stock_item_id' => $sku['id'], 'operation' => 'receipt', 'warehouse_node_id' => $a['id'],
            'quantity' => '12.250', 'reason' => 'Initial authorized delivery',
        ])->assertCreated()->assertJsonPath('data.quantity_milli', 12250);
        $this->postJson($base, [
            'stock_item_id' => $sku['id'], 'operation' => 'transfer', 'warehouse_node_id' => $a['id'],
            'target_warehouse_node_id' => $b['id'], 'quantity' => '2.125',
            'reason' => 'Moved to another warehouse',
        ])->assertCreated();
        $this->postJson($base, [
            'stock_item_id' => $sku['id'], 'operation' => 'issue', 'warehouse_node_id' => $b['id'],
            'quantity' => '1.250', 'reason' => 'Issued to service desk',
        ])->assertCreated();
        $this->assertSame(10125,
            StockBalance::query()->where('warehouse_node_id', $a['id'])->firstOrFail()->quantity_milli);
        $this->assertSame(875,
            StockBalance::query()->where('warehouse_node_id', $b['id'])->firstOrFail()->quantity_milli);
        $this->getJson($this->url($c, 'stock-balances?warehouse_node_id='.$b['id']))
            ->assertOk()->assertJsonPath('data.0.quantity_milli', 875);
        $this->postJson($base, [
            'stock_item_id' => $sku['id'], 'operation' => 'issue', 'warehouse_node_id' => $b['id'],
            'quantity' => '4', 'reason' => 'More than available',
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->postJson($base, [
            'stock_item_id' => $sku['id'], 'operation' => 'transfer', 'warehouse_node_id' => $a['id'],
            'target_warehouse_node_id' => $a['id'], 'quantity' => '1', 'reason' => 'Invalid same warehouse',
        ])->assertUnprocessable()->assertJsonValidationErrors('target_warehouse_node_id');

        $this->actingAs($this->actor($other));
        $this->getJson($this->url($c, 'stock-items'))->assertNotFound();
        $this->postJson($this->url($other, 'stock-transactions'), [
            'stock_item_id' => $sku['id'], 'operation' => 'issue', 'warehouse_node_id' => $a['id'],
            'quantity' => '1', 'reason' => 'Cross-company stock issue',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('stock_transactions', 3);
    }

    public function test_facility_scope_snapshot_captures_descendants_and_survives_relocation(): void
    {
        $c = $this->company('Mining');
        $other = $this->company('Solutions');
        $this->actingAs($this->actor($c));
        $site = $this->node($c, 'site', 'SITE');
        $building = $this->node($c, 'building', 'BUILDING', $site['id']);
        $floor = $this->node($c, 'floor', 'FLOOR', $building['id']);
        $room = $this->node($c, 'room', 'ROOM', $floor['id']);
        $elsewhere = $this->node($c, 'building', 'ELSEWHERE', $site['id']);
        $asset = $this->asset($c, 'FAC-001', $room['id']);
        $this->asset($c, 'FAC-002', $elsewhere['id']);

        $session = $this->postJson($this->url($c, 'inventory-sessions'), [
            'name' => 'First floor inspection', 'facility_node_id' => $floor['id'],
        ])->assertCreated()->assertJsonPath('data.scope_type', 'facility')
            ->assertJsonPath('data.total_items', 1)->json('data');
        $items = $this->getJson($this->url($c,'inventory-sessions/'.$session['id'].'/items'))->assertOk();
        $items->assertJsonPath('data.0.inventory_number','FAC-001')
            ->assertJsonPath('data.0.expected_facility_node_id',$room['id'])
            ->assertJsonPath('data.0.expected_facility_node_name','QA ROOM');

        $this->putJson($this->url($c,'assets/'.$asset['id']),[
            'inventory_number' => 'FAC-001', 'name' => 'Relocated item',
            'facility_node_id' => $elsewhere['id'], 'version' => 1,
        ])->assertOk()->assertJsonPath('data.facility_node_id',$elsewhere['id']);
        $this->getJson($this->url($c,'inventory-sessions/'.$session['id'].'/items'))
            ->assertJsonPath('data.0.expected_facility_node_id',$room['id']);

        $this->actingAs($this->actor($other));
        $this->getJson($this->url($other,'inventory-sessions/'.$session['id']))->assertNotFound();
    }
}
