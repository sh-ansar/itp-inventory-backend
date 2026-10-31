<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AssetDisposalTest extends TestCase
{
    use RefreshDatabase;

    private function setupCompany(string $role = 'company_admin'): array
    {
        $company = Company::query()->create(['id' => (string) Str::uuid(), 'name' => Str::random(8)]);
        $user = User::factory()->create(['is_active' => true]);
        $user->companyMemberships()->create(['company_id' => $company->id, 'role_code' => $role]);
        return [$company, $user];
    }

    private function url(Company $company, string $tail): string
    {
        return '/api/v1/companies/'.$company->id.$tail;
    }

    private function createAsset(Company $company): array
    {
        return $this->postJson($this->url($company, '/assets'), [
            'inventory_number' => 'ASSET-001', 'name' => 'Laptop',
        ])->assertCreated()->json('data');
    }

    private function decision(string $stage, int $version): array
    {
        return [
            'stage' => $stage, 'version' => $version,
            'document_number' => 'ACT-2026-01', 'document_date' => '2026-10-30',
            'reason' => 'Outdated equipment has been inspected',
            'disposal_method' => $stage === 'disposal' ? 'Certified recycling' : null,
            'document' => UploadedFile::fake()->create('signed-order.pdf', 24, 'application/pdf'),
        ];
    }

    public function test_write_off_and_disposal_require_orders_and_keep_history(): void
    {
        Storage::fake('local');
        [$company, $admin] = $this->setupCompany();
        $this->actingAs($admin);
        $asset = $this->createAsset($company);
        $url = $this->url($company, '/assets/'.$asset['id'].'/disposals');
        $this->post($url, $this->decision('write_off', 1), ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.stage', 'write_off')
            ->assertJsonPath('data.asset_status', 'written_off');
        $this->post($url, $this->decision('disposal', 2), ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.asset_status', 'disposed');
        $this->assertDatabaseCount('asset_disposals', 2);
        $this->assertDatabaseCount('asset_disposal_documents', 2);
        $this->getJson($this->url($company, '/asset-disposals'))->assertOk()
            ->assertJsonPath('meta.total', 2);
        $this->getJson($this->url($company, '/assets/'.$asset['id'].'/events'))
            ->assertJsonPath('data.0.event_type', 'disposal');
        $this->post($url, $this->decision('disposal', 3), ['Accept' => 'application/json'])
            ->assertStatus(409);
    }

    public function test_permissions_tenant_boundaries_and_conflicts(): void
    {
        Storage::fake('local');
        [$a, $admin] = $this->setupCompany();
        [$b, $other] = $this->setupCompany();
        $this->actingAs($admin);
        $asset = $this->createAsset($a);
        $url = $this->url($a, '/assets/'.$asset['id'].'/disposals');
        $this->post($url, $this->decision('disposal', 1), ['Accept'=>'application/json'])->assertStatus(409);
        $this->post($url, $this->decision('write_off', 99), ['Accept'=>'application/json'])->assertStatus(409);
        $this->actingAs($other);
        $this->post($url, $this->decision('write_off', 1), ['Accept'=>'application/json'])->assertNotFound();
        $this->getJson($this->url($a, '/asset-disposals'))->assertNotFound();
        $this->actingAs($admin);
        [$unused, $viewer] = [$a, User::factory()->create(['is_active'=>true])];
        $viewer->companyMemberships()->create(['company_id'=>$a->id,'role_code'=>'viewer']);
        $this->actingAs($viewer);
        $this->post($url, $this->decision('write_off', 1), ['Accept'=>'application/json'])->assertForbidden();
        $this->actingAs($admin);
        $this->post($url, array_diff_key($this->decision('write_off',1), ['document'=>true]),
            ['Accept'=>'application/json'])->assertUnprocessable()->assertJsonValidationErrors('document');
        $this->assertDatabaseCount('asset_disposals', 0);
    }

    public function test_documents_remain_private_and_members_only(): void
    {
        Storage::fake('local');
        [$a, $admin] = $this->setupCompany();
        [$b, $foreign] = $this->setupCompany();
        $this->actingAs($admin);
        $asset = $this->createAsset($a);
        $created = $this->post($this->url($a,'/assets/'.$asset['id'].'/disposals'),
            $this->decision('write_off',1), ['Accept'=>'application/json'])->assertCreated()->json('data');
        $document = $created['documents'][0]['id'];
        $path = '/asset-disposals/'.$created['id'].'/documents/'.$document;
        $this->get($this->url($a,$path))->assertOk();
        $this->actingAs($foreign);
        $this->get($this->url($a,$path))->assertNotFound();
        $this->get($this->url($b,$path))->assertNotFound();
    }
}
