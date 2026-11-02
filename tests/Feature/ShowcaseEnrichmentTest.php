<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ShowcaseEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_showcase_has_complete_presentable_workflows_with_private_sample_documents(): void
    {
        Storage::fake('local');
        $real = Company::create(['id' => (string) Str::uuid(), 'name' => 'Operational company']);
        $actor = User::factory()->create(['is_active'=>true]);
        $actor->companyMemberships()->create([
            'company_id' => $real->id, 'role_code' => 'company_admin',
        ]);
        $this->artisan('inventory:demo:seed')->assertExitCode(0);
        $this->artisan('inventory:demo:enrich', ['--dry-run' => true])->assertExitCode(0);
        $demo = Company::query()->where('name','like','[DEMO]%')->firstOrFail();
        $this->assertSame(14, $demo->assetCategories()->count());
        $this->assertSame(3, $demo->assetDisposalCases()->count());

        $this->artisan('inventory:demo:enrich')->assertExitCode(0);
        $this->assertSame(120, $demo->assets()->count());
        $this->assertSame(118, $demo->assets()->where('status','active')->count());
        $this->assertSame(1, $demo->assets()->where('status','written_off')->count());
        $this->assertSame(1, $demo->assets()->where('status','disposed')->count());
        $this->assertSame(4, $demo->assetDisposalCases()->count());
        foreach (['draft','submitted','approved','disposed'] as $status) {
            $this->assertSame(1, $demo->assetDisposalCases()->where('status',$status)->count());
        }
        $this->assertGreaterThanOrEqual(10, $demo->assetCategories()->where('is_base',true)->count());
        $this->assertGreaterThan(14, $demo->assetCategories()->count());
        $icon = $demo->assetCategories()->where('code','D-TL')->firstOrFail();
        $this->assertNotNull($icon->icon_path);
        $this->assertStringStartsWith('inventory-icons/'.$demo->id.'/', $icon->icon_path);
        $png = Storage::disk('local')->get($icon->icon_path);
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);

        $this->assertSame(4,$demo->stockTransactions()->where('operation','issue')->count());
        $session=$demo->inventorySessions()->where('name','ДЕМО · Плановая инвентаризация ИТ — закрыта')->firstOrFail();
        $this->assertSame(26,$session->total_items);
        $this->assertSame(26,$session->counted_items);
        $this->assertSame(0,$session->discrepancies);
        $this->assertSame('completed',$session->status);
        $this->assertGreaterThan(19,$session->events()->count());

        $docs=DB::table('asset_disposal_documents')->where('company_id',$demo->id)->get();
        $this->assertCount(4,$docs);
        foreach($docs as $doc){
            $this->assertStringStartsWith('inventory-disposals/'.$demo->id.'/',$doc->disk_path);
            $raw=Storage::disk('local')->get($doc->disk_path);
            $this->assertStringStartsWith('%PDF-1.4',$raw);
            $this->assertSame(hash('sha256',$raw),$doc->sha256);
        }
        $this->assertSame(0,$real->assets()->count());
        $this->assertSame(1,User::count(),'No new users or passwords');
        $beforeEvents=DB::table('asset_disposal_events')->where('company_id',$demo->id)->count();

        $this->artisan('inventory:demo:enrich')->assertExitCode(0);
        $this->assertSame(4,$demo->assetDisposalCases()->count());
        $this->assertSame(4,DB::table('asset_disposal_documents')->where('company_id',$demo->id)->count());
        $this->assertSame(4,$demo->stockTransactions()->where('operation','issue')->count());
        $this->assertSame($beforeEvents,DB::table('asset_disposal_events')->where('company_id',$demo->id)->count());
        $this->assertSame($icon->icon_path,$demo->assetCategories()->where('code','D-TL')->firstOrFail()->icon_path);
    }

    public function test_enrichment_refuses_a_company_that_is_not_the_fixed_showcase(): void
    {
        $this->artisan('inventory:demo:enrich')->assertExitCode(1);
        $this->assertSame(0,Company::count());
    }
}
