<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FacilityPlanTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $name): Company
    {
        return Company::create(['id' => (string) Str::uuid(), 'name' => $name]);
    }

    private function actor(Company $company, string $role = 'company_admin'): User
    {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->companyMemberships()->create(['company_id' => $company->id, 'role_code' => $role]);

        return $actor;
    }

    private function url(Company $company, string $node, string $suffix = ''): string
    {
        return '/api/v1/companies/'.$company->id.'/facility-nodes/'.$node.'/plan'.$suffix;
    }

    private function node(Company $company, string $code, string $type = 'floor', ?string $parent = null): array
    {
        if ($type === 'floor' && ! $parent) {
            $parent = $this->node($company, $code.'-BLD', 'building')['id'];
        }

        return $this->postJson('/api/v1/companies/'.$company->id.'/facility-nodes', [
            'code' => $code, 'name' => 'QA '.$code,
            'node_type' => $type, 'parent_id' => $parent,
        ])->assertCreated()->json('data');
    }

    private function asset(Company $company, string $node, string $code): array
    {
        return $this->postJson('/api/v1/companies/'.$company->id.'/assets', [
            'name' => 'QA device '.$code, 'inventory_number' => $code,
            'facility_node_id' => $node,
        ])->assertCreated()->json('data');
    }

    private function geometry(array $values = []): array
    {
        return ['x' => 48, 'y' => 72, 'width' => 180, 'height' => 120, ...$values];
    }

    public function test_blank_plan_is_readable_without_writing_a_database_row_and_viewer_cannot_edit(): void
    {
        $mining = $this->company('Mining');
        $this->actingAs($this->actor($mining));
        $floor = $this->node($mining, 'FLOOR-A');
        $room = $this->node($mining, 'ROOM-A', 'room', $floor['id']);
        $asset = $this->asset($mining, $floor['id'], 'CAMERA-001');
        $this->actingAs($this->actor($mining, 'viewer'));
        $plan = $this->getJson($this->url($mining, $floor['id']))
            ->assertOk()->assertJsonPath('data.version', 0)
            ->assertJsonPath('data.canvas_width', 1200)
            ->assertJsonCount(2, 'data.elements')
            ->json('data');
        $this->assertContains($room['id'], array_column($plan['elements'], 'id'));
        $this->assertContains($asset['id'], array_column($plan['elements'], 'id'));
        $this->assertDatabaseCount('facility_plans', 0);
        $this->postJson($this->url($mining, $floor['id'], '/placements'), [
            'child_node_id' => $room['id'], 'expected_version' => 0,
            ...$this->geometry(),
        ])->assertForbidden();
        $this->putJson($this->url($mining, $floor['id']), [
            'expected_version' => 0, 'canvas_width' => 1300, 'canvas_height' => 800,
        ])->assertForbidden();
        $this->getJson($this->url($mining, $floor['id'], '/events'))
            ->assertOk()->assertJsonCount(0, 'data');

        $other = $this->company('Solutions');
        $this->actingAs($this->actor($other));
        $this->getJson($this->url($mining, $floor['id']))->assertNotFound();
        $this->getJson($this->url($mining, $floor['id'], '/events'))->assertNotFound();
        $this->getJson($this->url($mining, $floor['id'], '/background'))->assertNotFound();
    }

    public function test_placement_move_resize_removal_versioning_and_immutable_audit(): void
    {
        $company = $this->company('Mining');
        $this->actingAs($this->actor($company));
        $floor = $this->node($company, 'FIRST-FLOOR');
        $room = $this->node($company, 'LAB-101', 'room', $floor['id']);
        $asset = $this->asset($company, $floor['id'], 'MON-001');
        $base = $this->url($company, $floor['id']);
        $placedRoom = $this->postJson($base.'/placements', [
            'child_node_id' => $room['id'], 'expected_version' => 0,
            ...$this->geometry(),
        ])->assertCreated()->assertJsonPath('data.version', 1)->json('data.placement');
        $this->postJson($base.'/placements', [
            'asset_id' => $asset['id'], 'expected_version' => 1,
            ...$this->geometry(['x' => 320, 'width' => 82, 'height' => 82]),
        ])->assertCreated()->assertJsonPath('data.version', 2);
        $listed = $this->getJson($base)->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonCount(2, 'data.elements')
            ->json('data.elements');
        $this->assertCount(2, array_filter($listed, fn ($e) => $e['placement_id'] !== null));

        $this->patchJson($base.'/placements/'.$placedRoom['id'], [
            'expected_version' => 2, ...$this->geometry(['x' => 180, 'width' => 240]),
        ])->assertOk()->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.placement.x', 180);
        $this->patchJson($base.'/placements/'.$placedRoom['id'], [
            'expected_version' => 2, ...$this->geometry(),
        ])->assertStatus(409);
        $this->putJson($base, [
            'expected_version' => 3, 'canvas_width' => 1400, 'canvas_height' => 900,
        ])->assertOk()->assertJsonPath('data.version', 4);
        $this->deleteJson($base.'/placements/'.$placedRoom['id'], [
            'expected_version' => 4,
        ])->assertOk()->assertJsonPath('data.version', 5);
        $this->getJson($base.'/events')->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.event_type', 'removed')
            ->assertJsonPath('data.0.version', 5);
        $this->assertDatabaseCount('facility_plan_placements', 1);
        $this->getJson($base)->assertJsonPath('data.version', 5);
    }

    public function test_invalid_geometry_duplicate_or_foreign_targets_and_conflict_are_rejected(): void
    {
        $mining = $this->company('Mining');
        $other = $this->company('Solutions');
        $this->actingAs($this->actor($mining));
        $floor = $this->node($mining, 'FLOOR-A');
        $room = $this->node($mining, 'ROOM-A', 'room', $floor['id']);
        $another = $this->node($mining, 'ROOM-B', 'room', $floor['id']);
        $foreignFloor = $this->node($mining, 'SECOND-FLOOR');
        $foreignAsset = $this->asset($mining, $foreignFloor['id'], 'NOT-HERE');
        $base = $this->url($mining, $floor['id']);

        $this->postJson($base.'/placements', [
            'child_node_id' => $room['id'], 'expected_version' => 0,
            ...$this->geometry(['x' => 1160]),
        ])->assertUnprocessable()->assertJsonValidationErrors('x');
        $this->postJson($base.'/placements', [
            'child_node_id' => $room['id'], 'asset_id' => $foreignAsset['id'],
            'expected_version' => 0, ...$this->geometry(),
        ])->assertUnprocessable()->assertJsonValidationErrors('asset_id');
        $this->postJson($base.'/placements', [
            'child_node_id' => $foreignFloor['id'],
            'expected_version' => 0, ...$this->geometry(),
        ])->assertNotFound();
        $this->postJson($base.'/placements', [
            'asset_id' => $foreignAsset['id'],
            'expected_version' => 0, ...$this->geometry(),
        ])->assertNotFound();
        $roomPlacement = $this->postJson($base.'/placements', [
            'child_node_id' => $room['id'], 'expected_version' => 0, ...$this->geometry(),
        ])->assertCreated()->json('data.placement');
        $this->postJson($base.'/placements', [
            'child_node_id' => $room['id'], 'expected_version' => 1, ...$this->geometry(),
        ])->assertUnprocessable()->assertJsonValidationErrors('child_node_id');
        $this->postJson($base.'/placements', [
            'child_node_id' => $another['id'], 'expected_version' => 0, ...$this->geometry(),
        ])->assertStatus(409);
        $this->putJson($base, [
            'expected_version' => 1, 'canvas_width' => 600, 'canvas_height' => 400,
        ])->assertOk()->assertJsonPath('data.version', 2);
        $this->postJson($base.'/placements', [
            'child_node_id' => $another['id'], 'expected_version' => 2,
            ...$this->geometry(['x' => 590]),
        ])->assertUnprocessable();
        $this->assertDatabaseCount('facility_plan_placements', 1);

        $this->actingAs($this->actor($other));
        $this->postJson($base.'/placements', [
            'child_node_id' => $another['id'], 'expected_version' => 2,
            ...$this->geometry(),
        ])->assertNotFound();
        $this->patchJson($base.'/placements/'.$roomPlacement['id'], [
            'expected_version' => 2, ...$this->geometry(),
        ])->assertNotFound();
    }

    public function test_visitor_cannot_downsize_canvas_beyond_existing_elements(): void
    {
        $company = $this->company('Mining');
        $this->actingAs($this->actor($company));
        $floor = $this->node($company, 'FLOOR-A');
        $room = $this->node($company, 'ROOM-A', 'room', $floor['id']);
        $base = $this->url($company, $floor['id']);
        $this->postJson($base.'/placements', [
            'child_node_id' => $room['id'], 'expected_version' => 0,
            ...$this->geometry(['x' => 1000, 'width' => 180]),
        ])->assertCreated();
        $this->putJson($base, [
            'expected_version' => 1, 'canvas_width' => 600, 'canvas_height' => 600,
        ])->assertUnprocessable()->assertJsonValidationErrors('canvas_width');
        $this->getJson($base)->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.canvas_width', 1200);
    }

    public function test_reparented_room_is_not_shown_in_old_plan_and_does_not_retain_visual_assignment_in_new_plan(): void
    {
        $company = $this->company('Mining');
        $this->actingAs($this->actor($company));
        $first = $this->node($company, 'FIRST-FLOOR');
        $second = $this->node($company, 'SECOND-FLOOR');
        $room = $this->node($company, 'ROOM-A', 'room', $first['id']);
        $oldPlan = $this->url($company, $first['id']);
        $newPlan = $this->url($company, $second['id']);

        $this->postJson($oldPlan.'/placements', [
            'child_node_id' => $room['id'], 'expected_version' => 0, ...$this->geometry(),
        ])->assertCreated();
        $this->putJson('/api/v1/companies/'.$company->id.'/facility-nodes/'.$room['id'], [
            'code' => 'ROOM-A', 'name' => 'QA ROOM-A', 'node_type' => 'room',
            'parent_id' => $second['id'], 'version' => 1,
        ])->assertOk();
        $this->getJson($oldPlan)->assertOk()->assertJsonCount(0, 'data.elements')
            ->assertJsonPath('data.version', 1);
        $this->getJson($newPlan)->assertOk()->assertJsonCount(1, 'data.elements')
            ->assertJsonPath('data.version', 0)
            ->assertJsonPath('data.elements.0.placement_id', null);
        $this->getJson($oldPlan.'/events')->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseCount('facility_plan_placements', 1);
    }

    public function test_background_upload_is_private_safely_reencoded_versioned_and_reversible(): void
    {
        Storage::fake('local');
        $company = $this->company('Mining');
        $other = $this->company('Solutions');
        $this->actingAs($this->actor($company));
        $floor = $this->node($company, 'FLOOR-A');
        $base = $this->url($company, $floor['id']);
        $this->postJson($base.'/background', [
            'file' => UploadedFile::fake()->image('plan.webp', 640, 480),
            'expected_version' => 0,
        ])->assertOk()->assertJsonPath('data.version', 1);
        $this->get($base.'/background')->assertOk()
            ->assertHeader('Content-Type', 'image/png');
        $this->getJson($base)->assertJsonPath('data.version', 1);
        $this->postJson($base.'/background', [
            'file' => UploadedFile::fake()->create('unsafe.svg', 100, 'image/svg+xml'),
            'expected_version' => 1,
        ])->assertUnprocessable();
        $this->postJson($base.'/background', [
            'file' => UploadedFile::fake()->image('plan.png', 640, 480),
            'expected_version' => 0,
        ])->assertStatus(409);
        $this->deleteJson($base.'/background', [
            'expected_version' => 1,
        ])->assertOk()->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.background_url', null);
        $this->get($base.'/background')->assertNotFound();
        $this->getJson($base.'/events')->assertJsonCount(2, 'data');
        $this->actingAs($this->actor($other));
        $this->get($base.'/background')->assertNotFound();
    }
}
