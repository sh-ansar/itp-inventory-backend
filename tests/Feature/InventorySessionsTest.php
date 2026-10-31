<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Company;
use App\Models\InventorySessionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InventorySessionsTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $name = 'ITP Mining'): Company
    {
        return Company::query()->create(['id' => (string) Str::uuid(), 'name' => $name]);
    }

    private function actor(Company $company, string $role = 'company_admin'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->companyMemberships()->create([
            'company_id' => $company->id, 'role_code' => $role,
        ]);

        return $user;
    }

    private function root(Company $company): string
    {
        return '/api/v1/companies/'.$company->id.'/inventory-sessions';
    }

    private function location(Company $company, string $code, string $kind = 'physical'): string
    {
        return $this->postJson('/api/v1/companies/'.$company->id.'/locations', [
            'code' => $code, 'name' => 'Warehouse '.$code, 'kind' => $kind,
        ])->assertCreated()->json('id');
    }

    private function asset(Company $company, string $number, ?string $location = null): array
    {
        return $this->postJson('/api/v1/companies/'.$company->id.'/assets', [
            'name' => 'Notebook '.$number, 'inventory_number' => $number,
            'physical_location_id' => $location,
        ])->assertCreated()->json('data');
    }

    private function start(Company $company, array $data = []): array
    {
        return $this->postJson($this->root($company), [
            'name' => 'Annual IT stocktake', ...$data,
        ])->assertCreated()->json('data');
    }

    private function items(Company $company, string $session): array
    {
        return $this->getJson($this->root($company).'/'.$session.'/items')
            ->assertOk()->json('data');
    }

    public function test_snapshot_uses_only_active_tenant_assets_and_is_immutable_after_asset_move(): void
    {
        $c = $this->company();
        $other = $this->company('Other tenant');
        $this->actingAs($this->actor($c));
        $old = $this->location($c, 'OLD');
        $new = $this->location($c, 'NEW');
        $a = $this->asset($c, 'MIN-001', $old);
        $b = $this->asset($c, 'MIN-002', $old);

        $this->actingAs($this->actor($other));
        $this->asset($other, 'SOL-001');
        $this->actingAs($this->actor($c));

        $session = $this->start($c);
        $this->assertSame('in_progress', $session['status']);
        $this->assertSame('all', $session['scope_type']);
        $this->assertSame(2, $session['total_items']);
        $this->assertSame(0, $session['counted_items']);
        $this->assertSame(0, $session['discrepancies']);
        $this->assertSame(1, $session['version']);
        $items = $this->items($c, $session['id']);
        $this->assertCount(2, $items);
        $this->assertSame('MIN-001', $items[0]['inventory_number']);
        $this->assertSame($old, $items[0]['expected_location_id']);
        $this->assertSame('Warehouse OLD', $items[0]['expected_location_name']);

        $this->postJson('/api/v1/companies/'.$c->id.'/assets/'.$a['id'].'/movements', [
            'to_location_id' => $new, 'version' => 1,
            'reason' => 'Moved during an open inventory',
        ])->assertCreated();
        $oldActual = $this->getJson('/api/v1/companies/'.$c->id.'/assets/'.$a['id'])
            ->assertOk()->json('data.physical_location_id');
        $this->assertSame($new, $oldActual);
        $item = $this->items($c, $session['id'])[0];
        $this->assertSame($old, $item['expected_location_id']);
        $this->assertSame('Warehouse OLD', $item['expected_location_name']);
        $this->assertDatabaseCount('inventory_session_events', 1);
        $this->assertDatabaseCount('inventory_session_items', 2);

        // Archived asset remains part of the start-time snapshot.
        $this->deleteJson('/api/v1/companies/'.$c->id.'/assets/'.$b['id'], [
            'version' => 1, 'reason' => 'End of useful life',
        ])->assertOk();
        $this->assertCount(2, $this->items($c, $session['id']));
    }

    public function test_location_scope_and_selected_assets_reject_foreign_deleted_and_wrong_kind(): void
    {
        $c = $this->company();
        $other = $this->company('Other tenant');
        $this->actingAs($this->actor($c));
        $office = $this->location($c, 'OFF');
        $warehouse = $this->location($c, 'WH');
        $accounting = $this->location($c, 'BAL', 'accounting');
        $a = $this->asset($c, 'MIN-001', $office);
        $b = $this->asset($c, 'MIN-002', $warehouse);

        $this->actingAs($this->actor($other));
        $foreign = $this->asset($other, 'SOL-999');
        $this->actingAs($this->actor($c));

        $scoped = $this->start($c, ['scope_location_id' => $office]);
        $this->assertSame(1, $scoped['total_items']);
        $this->assertSame('location', $scoped['scope_type']);
        $this->assertSame($office, $scoped['scope_location_id']);
        $this->assertSame('MIN-001', $this->items($c, $scoped['id'])[0]['inventory_number']);

        $selected = $this->start($c, ['asset_ids' => [$b['id']]]);
        $this->assertSame('selected', $selected['scope_type']);
        $this->assertSame('MIN-002', $this->items($c, $selected['id'])[0]['inventory_number']);

        $this->postJson($this->root($c), [
            'name' => 'Invalid foreign selection', 'asset_ids' => [$foreign['id']],
        ])->assertUnprocessable()->assertJsonValidationErrors('asset_ids');
        $this->postJson($this->root($c), [
            'name' => 'Invalid mixed selection', 'asset_ids' => [$a['id'], $foreign['id']],
        ])->assertUnprocessable()->assertJsonValidationErrors('asset_ids');
        $this->postJson($this->root($c), [
            'name' => 'Wrong location kind', 'scope_location_id' => $accounting,
        ])->assertUnprocessable()->assertJsonValidationErrors('scope_location_id');
        $this->postJson($this->root($c), [
            'name' => 'Wrong scope set', 'scope_location_id' => $office, 'asset_ids' => [$b['id']],
        ])->assertUnprocessable()->assertJsonValidationErrors('asset_ids');

        $this->assertDatabaseCount('inventory_sessions', 2);
    }

    public function test_count_and_recount_preserve_versions_counter_deltas_and_actor_snapshots(): void
    {
        $c = $this->company();
        $user = $this->actor($c);
        $this->actingAs($user);
        $expected = $this->location($c, 'A');
        $different = $this->location($c, 'B');
        $this->asset($c, 'MIN-001', $expected);
        $session = $this->start($c);
        $item = $this->items($c, $session['id'])[0];
        $url = $this->root($c).'/'.$session['id'].'/items/'.$item['id'];

        $this->putJson($url, [
            'version' => 1, 'actual_location_id' => $different, 'note' => 'Found in another room',
        ])->assertOk()
            ->assertJsonPath('data.item.is_counted', true)
            ->assertJsonPath('data.item.is_discrepancy', true)
            ->assertJsonPath('data.item.version', 2)
            ->assertJsonPath('data.session.counted_items', 1)
            ->assertJsonPath('data.session.discrepancies', 1)
            ->assertJsonPath('data.session.version', 2);

        $this->putJson($url, [
            'version' => 1, 'actual_location_id' => $expected,
        ])->assertStatus(409);
        $this->putJson($url, [
            'version' => 2, 'actual_location_id' => $expected,
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->putJson($url, [
            'version' => 2, 'actual_location_id' => $expected,
            'reason' => 'Corrected after a physical recheck',
        ])->assertOk()
            ->assertJsonPath('data.item.is_discrepancy', false)
            ->assertJsonPath('data.item.version', 3)
            ->assertJsonPath('data.session.discrepancies', 0)
            ->assertJsonPath('data.session.counted_items', 1)
            ->assertJsonPath('data.session.version', 3);

        $this->getJson($this->root($c).'/'.$session['id'].'/events')
            ->assertOk()->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.event_type', 'recounted')
            ->assertJsonPath('data.0.actor_name', $user->name)
            ->assertJsonPath('data.0.reason', 'Corrected after a physical recheck');
        $this->assertSame($expected, InventorySessionItem::findOrFail($item['id'])->actual_location_id);
        $this->assertSame($expected, Asset::query()->firstOrFail()->physical_location_id);
    }

    public function test_qr_scan_enforces_session_membership_and_no_duplicate(): void
    {
        $c = $this->company();
        $other = $this->company('Other tenant');
        $this->actingAs($this->actor($c));
        $here = $this->location($c, 'A');
        $inside = $this->asset($c, 'MIN-001', $here);
        $notSelected = $this->asset($c, 'MIN-002', $here);
        $session = $this->start($c, ['asset_ids' => [$inside['id']]]);
        $url = $this->root($c).'/'.$session['id'].'/scan';

        $this->postJson($url, [
            'public_id' => $inside['public_id'], 'actual_location_id' => $here,
            'note' => 'Verified by printed QR',
        ])->assertOk()
            ->assertJsonPath('data.session.counted_items', 1)
            ->assertJsonPath('data.item.inventory_number', 'MIN-001');
        $this->postJson($url, [
            'public_id' => $inside['public_id'], 'actual_location_id' => $here,
        ])->assertStatus(409);
        $this->postJson($url, [
            'public_id' => $notSelected['public_id'], 'actual_location_id' => $here,
        ])->assertNotFound();

        $this->actingAs($this->actor($other));
        $this->postJson($url, [
            'public_id' => $inside['public_id'], 'actual_location_id' => $here,
        ])->assertNotFound();

        $this->actingAs($this->actor($c, 'viewer'));
        $this->getJson($this->root($c).'/'.$session['id'].'/items')->assertOk();
        $this->postJson($url, [
            'public_id' => $inside['public_id'], 'actual_location_id' => $here,
        ])->assertForbidden();
        $this->assertDatabaseCount('inventory_session_events', 2);
    }

    public function test_complete_reopen_permissions_versions_and_missing_items(): void
    {
        $c = $this->company();
        $admin = $this->actor($c);
        $this->actingAs($admin);
        $location = $this->location($c, 'OFF');
        $this->asset($c, 'MIN-001', $location);
        $this->asset($c, 'MIN-002', $location);
        $session = $this->start($c);
        $item = $this->items($c, $session['id'])[0];
        $this->putJson($this->root($c).'/'.$session['id'].'/items/'.$item['id'], [
            'version' => 1, 'actual_location_id' => $location,
        ])->assertOk();
        $complete = $this->root($c).'/'.$session['id'].'/complete';
        $reopen = $this->root($c).'/'.$session['id'].'/reopen';

        $manager = $this->actor($c, 'inventory_manager');
        $this->actingAs($manager);
        $this->postJson($complete, ['version' => 1])->assertStatus(409);
        $closed = $this->postJson($complete, ['version' => 2])
            ->assertOk()->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.missing_items', 1)
            ->assertJsonPath('data.counted_items', 1)->json('data');
        $this->assertNotNull($closed['completed_at']);
        $this->postJson($complete, ['version' => 3])->assertStatus(409);
        $this->putJson($this->root($c).'/'.$session['id'].'/items/'.$item['id'], [
            'version' => 2, 'actual_location_id' => $location, 'reason' => 'Rechecking a closed session',
        ])->assertStatus(409);
        $this->postJson($reopen, [
            'version' => 3, 'reason' => 'Manager wants to reopen the inventory',
        ])->assertForbidden();

        $this->actingAs($admin);
        $this->postJson($reopen, ['version' => 3, 'reason' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson($reopen, [
            'version' => 2, 'reason' => 'Correction requested by warehouse manager',
        ])->assertStatus(409);
        $this->postJson($reopen, [
            'version' => 3, 'reason' => 'Correction requested by warehouse manager',
        ])->assertOk()->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.completed_at', null)
            ->assertJsonPath('data.version', 4);
        $this->getJson($this->root($c).'/'.$session['id'].'/events')
            ->assertOk()->assertJsonPath('meta.total', 4);
    }

    public function test_search_pagination_and_filtering_are_server_side(): void
    {
        $c = $this->company();
        $this->actingAs($this->actor($c));
        $location = $this->location($c, 'A');
        for ($i = 1; $i <= 30; $i++) {
            $this->asset($c, sprintf('INV-%03d', $i), $location);
        }
        $session = $this->start($c);
        $base = $this->root($c).'/'.$session['id'].'/items';

        $this->getJson($base.'?per_page=10&page=2')->assertOk()
            ->assertJsonPath('meta.total', 30)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonCount(10, 'data');
        $this->getJson($base.'?search=INV-029')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.inventory_number', 'INV-029');
        $this->getJson($base.'?counted=1')->assertJsonPath('meta.total', 0);
        $this->getJson($base.'?discrepancy=1')->assertJsonPath('meta.total', 0);
        $this->getJson($base.'?per_page=999')->assertUnprocessable();
        $this->getJson($base.'?search=%25')->assertJsonPath('meta.total', 0);

        $this->getJson($this->root($c).'?search=Annual')->assertJsonPath('meta.total', 1);
        $this->getJson($this->root($c).'?status=completed')->assertJsonPath('meta.total', 0);
    }

    public function test_csv_export_is_authorized_snapshot_and_prevents_excel_formula_execution(): void
    {
        $c = $this->company();
        $other = $this->company('Other tenant');
        $this->actingAs($this->actor($c));
        $location = $this->location($c, 'A');
        $this->asset($c, '=SUM(1+1)', $location);
        $session = $this->start($c);
        $url = $this->root($c).'/'.$session['id'].'/report.csv';
        $resp = $this->get($url)->assertOk();
        $this->assertStringContainsString('text/csv', $resp->headers->get('Content-Type'));
        $content = $resp->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString("'=SUM(1+1)", $content);
        $this->assertStringContainsString('Инвентарный №', $content);

        $this->actingAs($this->actor($c, 'viewer'));
        $this->get($url)->assertOk();
        $this->actingAs($this->actor($other));
        $this->get($url)->assertNotFound();
    }

    public function test_nonmember_viewer_and_foreign_item_cannot_mutate_anything(): void
    {
        $c = $this->company();
        $other = $this->company('Other tenant');
        $admin = $this->actor($c);
        $this->actingAs($admin);
        $loc = $this->location($c, 'HERE');
        $this->asset($c, 'MIN-001', $loc);
        $session = $this->start($c);
        $item = $this->items($c, $session['id'])[0];

        $this->actingAs($this->actor($c, 'viewer'));
        $this->getJson($this->root($c))->assertOk();
        $this->getJson($this->root($c).'/'.$session['id'])->assertOk();
        $this->postJson($this->root($c), ['name' => 'Viewer cannot create'])->assertForbidden();
        $this->putJson($this->root($c).'/'.$session['id'].'/items/'.$item['id'], [
            'version' => 1, 'actual_location_id' => $loc,
        ])->assertForbidden();

        $this->actingAs($this->actor($other));
        $this->getJson($this->root($c))->assertNotFound();
        $this->getJson($this->root($other).'/'.$session['id'])->assertNotFound();
        $this->getJson($this->root($other).'/'.$session['id'].'/items')->assertNotFound();
        $this->getJson($this->root($other).'/'.$session['id'].'/events')->assertNotFound();
        $this->postJson($this->root($other).'/'.$session['id'].'/complete',[
            'version' => 1,
        ])->assertNotFound();
        $this->assertDatabaseCount('inventory_session_events',1);
    }

    public function test_all_unavailable_company_assets_cannot_start_empty_inventory(): void
    {
        $c = $this->company();
        $this->actingAs($this->actor($c));
        $this->postJson($this->root($c),[
            'name' => 'No active assets yet',
        ])->assertUnprocessable()->assertJsonValidationErrors('asset_ids');
        $this->assertDatabaseCount('inventory_sessions',0);
        $this->assertDatabaseCount('inventory_session_events',0);
    }
}
