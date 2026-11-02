<?php

namespace Tests\Feature;

use App\Console\Commands\SeedShowcaseInventory;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ShowcaseSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_showcase_is_complete_idempotent_and_isolated_from_existing_company(): void
    {
        Storage::fake('local');
        $original = Company::create(['id'=>(string) Str::uuid(),'name'=>'Real company untouched']);
        $admin = User::factory()->create(['is_active'=>true]);
        $admin->companyMemberships()->create([
            'company_id'=>$original->id,'role_code'=>'company_admin',
        ]);
        $this->artisan('inventory:demo:seed',['--dry-run'=>true])->assertExitCode(0);
        $this->assertDatabaseMissing('companies',['id'=>SeedShowcaseInventory::COMPANY_ID]);
        $this->artisan('inventory:demo:seed')->assertExitCode(0);
        $demo=Company::findOrFail(SeedShowcaseInventory::COMPANY_ID);
        $this->assertSame(120,$demo->assets()->count());
        $this->assertSame(44,$demo->facilityNodes()->count());
        $this->assertSame(12,$demo->responsiblePeople()->count());
        $this->assertSame(14,$demo->assetCategories()->count());
        $this->assertSame(16,$demo->stockItems()->count());
        $this->assertSame(3,$demo->inventorySessions()->count());
        $this->assertSame(37,$demo->facilityPlans()->count());
        $this->assertSame(3,$demo->assetDisposalCases()->count());
        $this->assertSame(2,$demo->facilityNodes()->where('node_type','warehouse')->count());
        $this->assertSame(2,DB::table('inventory_session_documents')
            ->where('company_id',$demo->id)->count());
        $this->assertSame(2,$demo->inventorySessions()->where('status','completed')->count());
        $this->assertSame(1,$demo->inventorySessions()->where('status','in_progress')->count());
        $this->assertSame(3,$demo->assetDisposalCases()->where('status','draft')->count());
        $this->assertGreaterThan(0,$demo->stockTransactions()->count());
        $this->assertGreaterThan(0,DB::table('facility_plan_placements')->where('company_id',$demo->id)->count());
        $this->assertTrue($demo->assets()->whereNull('responsible_person_id')->doesntExist());
        $this->assertTrue($demo->assets()->whereNull('facility_node_id')->doesntExist());
        foreach(DB::table('inventory_session_documents')->where('company_id',$demo->id)->get() as $document){
            $raw=Storage::disk('local')->get($document->storage_path);
            $this->assertStringStartsWith('%PDF-1.4',$raw);
            $this->assertSame(hash('sha256',$raw),$document->sha256);
        }
        $this->assertDatabaseHas('company_memberships',[
            'user_id'=>$admin->id,'company_id'=>$demo->id,'role_code'=>'company_admin',
        ]);
        $this->assertSame(1,User::count(),'Seeder must not create new logins or passwords');
        $this->assertSame(0,$original->assets()->count(),'Existing production company must be unchanged');
        $this->artisan('inventory:demo:seed')->assertExitCode(0);
        $this->assertSame(120,$demo->assets()->count());
        $this->assertSame(2,DB::table('inventory_session_documents')->where('company_id',$demo->id)->count());
    }

    public function test_refuses_multiple_administrators_without_creating_data(): void
    {
        $original=Company::create(['id'=>(string) Str::uuid(),'name'=>'Real company']);
        foreach(range(1,2) as $n){
            $admin=User::factory()->create(['is_active'=>true]);
            $admin->companyMemberships()->create(['company_id'=>$original->id,'role_code'=>'company_admin']);
        }
        $this->artisan('inventory:demo:seed')->assertExitCode(1);
        $this->assertDatabaseMissing('companies',['id'=>SeedShowcaseInventory::COMPANY_ID]);
    }
}
