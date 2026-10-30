<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetMovement;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AssetMovementQrTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $name = 'ITP Mining'): Company
    {
        return Company::query()->create([
            'id' => (string) Str::uuid(), 'name' => $name,
        ]);
    }

    private function user(Company $company, string $role = 'company_admin'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->companyMemberships()->create([
            'company_id' => $company->id, 'role_code' => $role,
        ]);
        return $user;
    }

    private function assetUrl(Company $company, string $asset = ''): string
    {
        return '/api/v1/companies/'.$company->id.'/assets'.($asset === '' ? '' : '/'.$asset);
    }

    private function createAsset(Company $company, string $number = 'MIN-001'): array
    {
        return $this->postJson($this->assetUrl($company), [
            'name' => 'Dell Latitude', 'inventory_number' => $number,
        ])->assertCreated()->json('data');
    }

    private function location(Company $company, string $code, string $kind = 'physical'): string
    {
        return $this->postJson('/api/v1/companies/'.$company->id.'/locations', [
            'kind' => $kind, 'code' => $code, 'name' => $kind.' '.$code,
        ])->assertCreated()->json('id');
    }

    public function test_qr_public_id_resolves_only_with_authenticated_company_membership(): void
    {
        $a = $this->company();
        $b = $this->company('Other company');
        $this->actingAs($this->user($a));
        $asset = $this->createAsset($a);
        $this->assertNotSame($asset['inventory_number'], $asset['public_id']);

        $url = $this->assetUrl($a, 'scan/'.$asset['public_id']);
        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.id', $asset['id'])
            ->assertJsonPath('data.public_id', $asset['public_id']);
        $this->getJson($this->assetUrl($a, 'scan/'.Str::uuid()))->assertNotFound();
        $this->getJson($this->assetUrl($a, 'scan/not-a-uuid'))->assertNotFound();
        $this->getJson($this->assetUrl($b, 'scan/'.$asset['public_id']))->assertNotFound();

        $this->actingAs($this->user($b));
        $this->getJson($url)->assertNotFound();
        $this->getJson($this->assetUrl($b, 'scan/'.$asset['public_id']))->assertNotFound();

        $this->getJson($this->assetUrl($b, $asset['id']))->assertNotFound();
        $this->assertSame('active', Asset::query()->findOrFail($asset['id'])->status);
    }

    public function test_successful_transfer_saves_location_version_audit_and_name_snapshots(): void
    {
        $company = $this->company();
        $this->actingAs($this->user($company));
        $a = $this->location($company, 'OFFICE-A');
        $b = $this->location($company, 'OFFICE-B');
        $asset = $this->createAsset($company);
        $url = $this->assetUrl($company, $asset['id'].'/movements');

        $this->postJson($url, [
            'to_location_id' => $a, 'version' => 1,
            'reason' => 'Первичная выдача ноутбука',
        ])->assertCreated()
            ->assertJsonPath('data.asset.version', 2)
            ->assertJsonPath('data.movement.version', 2)
            ->assertJsonPath('data.movement.from_location_id', null)
            ->assertJsonPath('data.movement.to_location_id', $a)
            ->assertJsonPath('data.movement.to_location_name', 'physical OFFICE-A');

        $this->postJson($url, [
            'to_location_id' => $b, 'version' => 2,
            'reason' => 'Перевод сотрудника в другой офис',
        ])->assertCreated()
            ->assertJsonPath('data.asset.version', 3)
            ->assertJsonPath('data.asset.physical_location_id', $b)
            ->assertJsonPath('data.movement.from_location_id', $a)
            ->assertJsonPath('data.movement.from_location_name', 'physical OFFICE-A')
            ->assertJsonPath('data.movement.to_location_name', 'physical OFFICE-B');

        $this->getJson($url)->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.version', 3)
            ->assertJsonPath('data.1.version', 2);

        $this->getJson($this->assetUrl($company, $asset['id'].'/events'))->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.event_type', 'moved')
            ->assertJsonPath('data.0.reason', 'Перевод сотрудника в другой офис');

        $this->assertDatabaseCount('asset_movements', 2);
        $this->assertDatabaseCount('asset_events', 3);
        $this->assertSame(3, Asset::query()->findOrFail($asset['id'])->version);
        $this->assertSame('active', Asset::query()->findOrFail($asset['id'])->status);

        $company->assetLocations()->whereKey($a)->update(['name'=>'Renamed office A']);
        $this->getJson($url)->assertJsonPath('data.0.from_location_name', 'physical OFFICE-A');
    }

    public function test_transfer_validates_target_status_version_role_and_tenant(): void
    {
        $a = $this->company();
        $b = $this->company('Other');
        $this->actingAs($this->user($a));
        $source = $this->location($a, 'A');
        $accounting = $this->location($a, 'BALANCE', 'accounting');
        $asset = $this->createAsset($a);
        $url = $this->assetUrl($a, $asset['id'].'/movements');
        $this->postJson($url, [
            'to_location_id' => $source, 'version' => 1,
            'reason' => 'Размещение в офисе',
        ])->assertCreated();

        $this->postJson($url, [
            'to_location_id' => $source, 'version' => 2,
            'reason' => 'Повторное размещение',
        ])->assertUnprocessable()->assertJsonValidationErrors('to_location_id');

        $this->postJson($url, [
            'to_location_id' => $accounting, 'version' => 2,
            'reason' => 'Нельзя использовать бухгалтерскую локацию',
        ])->assertUnprocessable()->assertJsonValidationErrors('to_location_id');

        $this->postJson($url, [
            'to_location_id' => Str::uuid(), 'version' => 2,
            'reason' => 'Неизвестное место',
        ])->assertUnprocessable()->assertJsonValidationErrors('to_location_id');

        $this->actingAs($this->user($b));
        $foreignLocation = $this->location($b, 'SECRET');
        $this->postJson($this->assetUrl($b, $asset['id'].'/movements'), [
            'to_location_id' => $foreignLocation, 'version' => 2,
            'reason' => 'Попытка доступа к чужому активу',
        ])->assertNotFound();

        $this->actingAs($this->user($a));
        $this->postJson($url, [
            'to_location_id' => $foreignLocation, 'version' => 2,
            'reason' => 'Попытка чужой локации',
        ])->assertUnprocessable()->assertJsonValidationErrors('to_location_id');
        $target = $this->location($a, 'B');
        $this->postJson($url, [
            'to_location_id' => $target, 'version' => 1,
            'reason' => 'Старая версия записи',
        ])->assertStatus(409);

        $this->actingAs($this->user($a, 'viewer'));
        $this->getJson($url)->assertJsonPath('meta.total', 1);
        $this->postJson($url, [
            'to_location_id' => $target, 'version' => 2,
            'reason' => 'Зритель не имеет прав',
        ])->assertForbidden();

        $this->actingAs($this->user($a, 'inventory_manager'));
        $this->postJson($url, [
            'to_location_id' => $target, 'version' => 2,
            'reason' => 'Авторизованное перемещение',
        ])->assertCreated()->assertJsonPath('data.asset.version', 3);

        Asset::query()->findOrFail($asset['id'])->update(['status'=>'written_off']);
        $this->postJson($url, [
            'to_location_id' => $source, 'version' => 3,
            'reason' => 'Запрещено перемещать списанный актив',
        ])->assertStatus(409);
        $this->assertDatabaseCount('asset_movements', 2);
        $this->assertDatabaseCount('asset_events', 3);
    }

    public function test_archived_asset_retains_transfer_history_but_qr_cannot_resolve(): void
    {
        $company = $this->company();
        $this->actingAs($this->user($company));
        $target = $this->location($company, 'OFFICE');
        $asset = $this->createAsset($company);
        $this->postJson($this->assetUrl($company, $asset['id'].'/movements'), [
            'to_location_id' => $target, 'version' => 1,
            'reason' => 'Доставка в офис компании',
        ])->assertCreated();

        $this->deleteJson($this->assetUrl($company, $asset['id']), [
            'version' => 2, 'reason' => 'Перенесено в архив',
        ])->assertOk();

        $this->getJson($this->assetUrl($company,'scan/'.$asset['public_id']))->assertNotFound();
        $this->getJson($this->assetUrl($company,$asset['id'].'/movements'))
            ->assertOk()->assertJsonPath('meta.total',1);
        $this->getJson($this->assetUrl($company,$asset['id'].'/events'))
            ->assertOk()->assertJsonPath('meta.total',3);
        $this->assertSoftDeleted('assets', ['id'=>$asset['id']]);
    }

    public function test_transfer_does_not_trust_actor_company_or_free_form_status(): void
    {
        $company = $this->company();
        $user = $this->user($company);
        $this->actingAs($user);
        $target = $this->location($company,'WAREHOUSE');
        $asset = $this->createAsset($company);
        $this->postJson($this->assetUrl($company,$asset['id'].'/movements'), [
            'to_location_id' => $target, 'version' => 1,
            'reason' => 'Получение товара на склад',
            'actor_id' => 999999,
            'company_id' => (string) Str::uuid(),
            'status' => 'disposed',
        ])->assertCreated()
            ->assertJsonPath('data.movement.actor_name',$user->name)
            ->assertJsonPath('data.asset.status','active');
        $movement = AssetMovement::query()->firstOrFail();
        $this->assertSame($company->id,$movement->company_id);
        $this->assertSame($user->id,$movement->actor_id);
    }
}