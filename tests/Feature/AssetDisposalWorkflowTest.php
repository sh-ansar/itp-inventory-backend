<?php

namespace Tests\Feature;

use App\Models\AssetDisposalDocument;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AssetDisposalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $name = 'Mining'): Company
    {
        return Company::create(['id' => (string) Str::uuid(), 'name' => $name]);
    }

    private function member(Company $company, string $role = 'inventory_manager'): User
    {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->companyMemberships()->create(['company_id' => $company->id, 'role_code' => $role]);

        return $actor;
    }

    private function asset(Company $company, string $number): array
    {
        return $this->postJson('/api/v1/companies/'.$company->id.'/assets', [
            'name' => 'Laptop '.$number,
            'inventory_number' => $number,
        ])->assertCreated()->json('data');
    }

    private function base(Company $company, string $assetId): string
    {
        return '/api/v1/companies/'.$company->id.'/assets/'.$assetId.'/disposal-case';
    }

    private function draft(Company $company, string $assetId, array $input = []): array
    {
        return $this->postJson($this->base($company, $assetId), [
            'asset_version' => 1,
            'reason' => 'The hardware cannot be repaired economically',
            'order_number' => 'ITP-ORDER-71',
            'order_date' => '2026-10-15',
            ...$input,
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data');
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('signed-order.pdf',
            "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<<>>\n%%EOF\n");
    }

    private function document(
        Company $company, string $assetId, string $type, int $version,
        ?UploadedFile $file = null
    ): array {
        return $this->post($this->base($company, $assetId).'/documents', [
            'document_type' => $type,
            'version' => $version,
            'file' => $file ?? $this->pdf(),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');
    }

    public function test_end_to_end_order_approval_physical_disposal_private_documents_and_asset_audit(): void
    {
        Storage::fake('local');
        $company = $this->company();
        $manager = $this->member($company);
        $admin = $this->member($company, 'company_admin');
        $this->actingAs($manager);
        $asset = $this->asset($company, 'ITP-001');
        $base = $this->base($company, $asset['id']);
        $draft = $this->draft($company, $asset['id']);
        $caseId = $draft['id'];
        $this->assertSame(1, $draft['version']);

        $this->postJson($base.'/submit', ['version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('documents');
        $this->postJson($base.'/approve', ['version' => 1, 'asset_version' => 1])
            ->assertForbidden();

        $draft = $this->document($company, $asset['id'], 'write_off_order', 1);
        $this->assertSame(2, $draft['version']);
        $this->assertCount(1, $draft['documents']);
        $docId = $draft['documents'][0]['id'];
        $this->assertSame('signed-order.pdf', $draft['documents'][0]['original_name']);
        $this->assertEquals(64, strlen($draft['documents'][0]['sha256']));
        $this->get($base.'/documents/'.$docId)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/octet-stream')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->postJson($base.'/submit', ['version' => 2])
            ->assertOk()->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.version', 3);
        $this->postJson($base.'/dispose', [
            'version' => 3, 'asset_version' => 1, 'disposal_act_number' => 'ACT-1',
            'disposal_method' => 'recycling', 'disposal_date' => '2026-10-30',
        ])->assertForbidden();

        $this->actingAs($admin);
        $this->postJson($base.'/approve', ['version' => 3, 'asset_version' => 1])
            ->assertOk()->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.asset.status', 'written_off')
            ->assertJsonPath('data.asset.version', 2)
            ->assertJsonPath('data.version', 4);
        $this->getJson('/api/v1/companies/'.$company->id.'/assets?status=written_off')
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->putJson('/api/v1/companies/'.$company->id.'/assets/'.$asset['id'], [
            'version' => 2, 'inventory_number' => 'ITP-001', 'name' => 'Alter after write-off',
        ])->assertStatus(409);
        $this->deleteJson('/api/v1/companies/'.$company->id.'/assets/'.$asset['id'], [
            'version' => 2, 'reason' => 'Bypass disposal',
        ])->assertStatus(409);
        $this->postJson($base.'/dispose', [
            'version' => 4, 'asset_version' => 2, 'disposal_act_number' => 'ACT-1',
            'disposal_method' => 'recycling', 'disposal_date' => '2026-10-30',
        ])->assertUnprocessable()->assertJsonValidationErrors('documents');

        $approved = $this->document($company, $asset['id'], 'disposal_act', 4,
            UploadedFile::fake()->image('destruction-act.png', 640, 480));
        $this->assertSame(5, $approved['version']);
        $this->assertCount(2, $approved['documents']);
        $this->assertSame('image/png', $this->getJson($base)->json('data.documents.1')
            ? AssetDisposalDocument::findOrFail($approved['documents'][1]['id'])->mime_type : null);
        $this->postJson($base.'/dispose', [
            'version' => 5, 'asset_version' => 2,
            'disposal_act_number' => 'DISPOSE-ACT-12',
            'disposal_date' => '2026-10-30',
            'disposal_method' => 'recycling',
        ])->assertOk()->assertJsonPath('data.status', 'disposed')
            ->assertJsonPath('data.asset.status', 'disposed')
            ->assertJsonPath('data.asset.version', 3)
            ->assertJsonPath('data.version', 6)
            ->assertJsonPath('data.disposal_act_number', 'DISPOSE-ACT-12');

        $this->postJson($base.'/dispose', [
            'version' => 6, 'asset_version' => 3,
            'disposal_act_number' => 'REPEAT',
            'disposal_date' => '2026-10-30',
            'disposal_method' => 'recycling',
        ])->assertStatus(409);
        $this->post($base.'/documents', [
            'version' => 6, 'document_type' => 'supporting', 'file' => $this->pdf(),
        ], ['Accept' => 'application/json'])->assertStatus(409);
        $this->postJson($base, [
            'asset_version' => 3, 'reason' => 'Repeat disposal is not allowed',
        ])->assertStatus(409);
        $this->getJson($base.'/events')->assertOk()->assertJsonCount(6, 'data')
            ->assertJsonPath('data.0.event_type', 'disposed')
            ->assertJsonPath('data.0.version', 6);
        $this->getJson('/api/v1/companies/'.$company->id.'/assets/'.$asset['id'].'/events')
            ->assertOk()->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.event_type', 'disposed');
        $this->assertDatabaseCount('asset_disposal_cases', 1);
        $this->assertDatabaseCount('asset_disposal_documents', 2);
        $this->assertDatabaseCount('asset_disposal_events', 6);
        $this->assertSame($caseId, $this->getJson($base)->json('data.id'));
    }

    public function test_admin_can_return_submitted_request_for_corrections_without_changing_asset(): void
    {
        Storage::fake('local');
        $company = $this->company();
        $manager = $this->member($company);
        $admin = $this->member($company, 'company_admin');
        $this->actingAs($manager);
        $asset = $this->asset($company, 'ITP-002');
        $base = $this->base($company, $asset['id']);
        $this->draft($company, $asset['id']);
        $this->document($company, $asset['id'], 'write_off_order', 1);
        $this->postJson($base.'/submit', ['version' => 2])->assertOk();
        $this->putJson($base, [
            'version' => 3, 'reason' => 'Cannot change after submission',
        ])->assertStatus(409);
        $this->postJson($base.'/return', ['version' => 3, 'reason' => 'Missing asset photos'])
            ->assertForbidden();
        $this->actingAs($admin);
        $this->postJson($base.'/return', ['version' => 3, 'reason' => 'Missing asset photos'])
            ->assertOk()->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.version', 4)
            ->assertJsonPath('data.asset.status', 'active');

        $this->actingAs($manager);
        $this->putJson($base, [
            'version' => 4, 'reason' => 'The hardware is defective and impossible to repair',
            'order_number' => 'ITP-ORDER-72', 'order_date' => '2026-10-29',
        ])->assertOk()->assertJsonPath('data.version', 5)
            ->assertJsonPath('data.order_number', 'ITP-ORDER-72');
        $this->postJson($base.'/submit', ['version' => 5])
            ->assertOk()->assertJsonPath('data.version', 6);
        $this->actingAs($admin);
        $this->postJson($base.'/approve', ['version' => 5, 'asset_version' => 1])
            ->assertStatus(409);
        $this->postJson($base.'/approve', ['version' => 6, 'asset_version' => 1])
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $events = $this->getJson($base.'/events')->assertOk()->json('data');
        $return = collect($events)->firstWhere('event_type', 'returned');
        $this->assertSame('Missing asset photos', $return['reason']);
        $this->assertDatabaseCount('asset_disposal_events', 7);
    }

    public function test_company_isolation_viewer_scope_and_immutable_file_ownership(): void
    {
        Storage::fake('local');
        $a = $this->company();
        $b = $this->company('Solutions');
        $this->actingAs($this->member($a));
        $asset = $this->asset($a, 'MINING-001');
        $base = $this->base($a, $asset['id']);
        $this->draft($a, $asset['id']);
        $case = $this->document($a, $asset['id'], 'write_off_order', 1);
        $docId = $case['documents'][0]['id'];
        $this->actingAs($this->member($a, 'viewer'));
        $this->getJson($base)->assertOk()->assertJsonPath('data.status', 'draft');
        $this->getJson($base.'/events')->assertOk();
        $this->get($base.'/documents/'.$docId)->assertOk();
        $this->getJson('/api/v1/companies/'.$a->id.'/disposal-cases')
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson($base.'/submit', ['version' => 2])->assertForbidden();
        $this->post($base.'/documents', [
            'version' => 2, 'document_type' => 'supporting', 'file' => $this->pdf(),
        ], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($this->member($b, 'company_admin'));
        $this->getJson('/api/v1/companies/'.$a->id.'/disposal-cases')->assertNotFound();
        $this->getJson($base)->assertNotFound();
        $this->getJson($base.'/events')->assertNotFound();
        $this->get($base.'/documents/'.$docId)->assertNotFound();
        $this->postJson($base.'/approve', ['version' => 2, 'asset_version' => 1])
            ->assertNotFound();
        $this->getJson($this->base($b, $asset['id']))->assertNotFound();
        $this->assertDatabaseCount('asset_disposal_documents', 1);
    }

    public function test_documents_and_required_fields_are_validated_and_failed_uploads_leave_no_files(): void
    {
        Storage::fake('local');
        $company = $this->company();
        $this->actingAs($this->member($company));
        $asset = $this->asset($company, 'ITP-003');
        $base = $this->base($company, $asset['id']);
        $this->draft($company, $asset['id'], [
            'order_number' => null, 'order_date' => null,
        ]);
        $this->postJson($base.'/submit', ['version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors(['order_number', 'documents']);
        $this->post($base.'/documents', [
            'version' => 1, 'document_type' => 'write_off_order',
            'file' => UploadedFile::fake()->create('attack.svg', 20, 'image/svg+xml'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post($base.'/documents', [
            'version' => 1, 'document_type' => 'disposal_act', 'file' => $this->pdf(),
        ], ['Accept' => 'application/json'])->assertStatus(409);
        $this->post($base.'/documents', [
            'version' => 3, 'document_type' => 'supporting', 'file' => $this->pdf(),
        ], ['Accept' => 'application/json'])->assertStatus(409);
        $this->assertDatabaseCount('asset_disposal_documents', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles('inventory-disposals'));
        $this->getJson($base.'/events')->assertJsonCount(1, 'data');
    }

    public function test_only_one_case_per_asset_and_queue_filters_by_current_company(): void
    {
        $company = $this->company();
        $other = $this->company('Solutions');
        $this->actingAs($this->member($company));
        $asset1 = $this->asset($company, 'MIN-101');
        $asset2 = $this->asset($company, 'MIN-102');
        $case = $this->draft($company, $asset1['id']);
        $this->draft($company, $asset2['id']);
        $this->postJson($this->base($company, $asset1['id']), [
            'asset_version' => 1, 'reason' => 'Cannot create a duplicate write-off case',
        ])->assertStatus(409);
        $this->getJson('/api/v1/companies/'.$company->id.'/disposal-cases?status=draft&search=MIN-101')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $case['id']);
        $this->getJson('/api/v1/companies/'.$company->id.'/disposal-cases?search=%25')
            ->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/companies/'.$company->id.'/disposal-cases?per_page=100')
            ->assertUnprocessable();
        $this->actingAs($this->member($other));
        $otherAsset = $this->asset($other, 'MIN-101');
        $this->draft($other, $otherAsset['id']);
        $this->getJson('/api/v1/companies/'.$other->id.'/disposal-cases')
            ->assertJsonPath('meta.total', 1);
    }
}
