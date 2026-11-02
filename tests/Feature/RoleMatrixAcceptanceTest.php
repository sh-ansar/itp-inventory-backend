<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Industrial RBAC acceptance gate: UI visibility is NOT authorization.
 * Every listed endpoint must enforce the current tenant membership server-side.
 */
final class RoleMatrixAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $name): Company
    {
        return Company::create(['id'=>(string)Str::uuid(),'name'=>$name]);
    }

    private function actor(Company $company, string $role): User
    {
        $user=User::factory()->create(['is_active'=>true]);
        $user->companyMemberships()->create([
            'company_id'=>$company->id,'role_code'=>$role,
        ]);

        return $user;
    }

    private function root(Company $company): string
    {
        return '/api/v1/companies/'.$company->id;
    }

    public function test_role_matrix_enforces_read_write_and_admin_only_operations_for_all_three_roles(): void
    {
        $company=$this->tenant('RBAC QA');
        $other=$this->tenant('Other tenant');
        $unknownAsset=(string) Str::uuid();
        $unknownNode=(string) Str::uuid();
        $unknownSession=(string) Str::uuid();
        $foreignRoot=$this->root($other);

        foreach (['viewer','inventory_manager','company_admin'] as $role) {
            $this->actingAs($this->actor($company,$role));
            $base=$this->root($company);

            // All three roles can browse their company's assets, warehouse,
            // facility graph, people, stocktakes and filtered reports.
            foreach ([
                '/assets','/facility-nodes','/reference-data','/responsible-people',
                '/stock-items','/inventory-sessions','/reports/summary',
                '/reports/forms/asset-register',
            ] as $endpoint) {
                $this->getJson($base.$endpoint)->assertOk();
                // Never disclose the existence or metadata of another tenant.
                $this->getJson($foreignRoot.$endpoint)->assertNotFound();
            }

            // Direct role URLs and direct API requests must not bypass menu visibility.
            if ($role==='company_admin') {
                $this->getJson($base.'/members')->assertOk();
                $this->postJson($base.'/assets',[])->assertUnprocessable();
                $this->postJson($base.'/facility-nodes',[])->assertUnprocessable();
                $this->postJson($base.'/inventory-sessions',[])->assertUnprocessable();
                $this->postJson($base.'/stock-items',[])->assertUnprocessable();
                $this->deleteJson($base.'/assets/'.$unknownAsset)->assertUnprocessable();
                $this->postJson($base.'/assets/'.$unknownAsset.'/disposal-case/approve',[])
                    ->assertUnprocessable();
                $this->postJson($base.'/inventory-sessions/'.$unknownSession.'/reopen',[])
                    ->assertUnprocessable();
            } elseif ($role==='inventory_manager') {
                $this->getJson($base.'/members')->assertForbidden();
                $this->postJson($base.'/assets',[])->assertUnprocessable();
                $this->postJson($base.'/facility-nodes',[])->assertUnprocessable();
                $this->postJson($base.'/inventory-sessions',[])->assertUnprocessable();
                $this->postJson($base.'/stock-items',[])->assertUnprocessable();
                $this->deleteJson($base.'/assets/'.$unknownAsset)->assertForbidden();
                $this->postJson($base.'/assets/'.$unknownAsset.'/disposal-case/approve',[])
                    ->assertForbidden();
                $this->postJson($base.'/inventory-sessions/'.$unknownSession.'/reopen',[])
                    ->assertForbidden();
            } else {
                $this->getJson($base.'/members')->assertForbidden();
                $this->postJson($base.'/assets',[])->assertForbidden();
                $this->postJson($base.'/facility-nodes',[])->assertForbidden();
                $this->postJson($base.'/inventory-sessions',[])->assertForbidden();
                $this->postJson($base.'/stock-items',[])->assertForbidden();
                $this->deleteJson($base.'/assets/'.$unknownAsset)->assertForbidden();
                $this->postJson($base.'/assets/'.$unknownAsset.'/disposal-case/approve',[])
                    ->assertForbidden();
                $this->postJson($base.'/inventory-sessions/'.$unknownSession.'/reopen',[])
                    ->assertForbidden();
            }
            $this->getJson($base.'/facility-nodes/'.$unknownNode.'/plan')->assertNotFound();
            $this->getJson($base.'/inventory-sessions/'.$unknownSession.'/documents')
                ->assertNotFound();
            $this->getJson($foreignRoot.'/members')->assertNotFound();
        }
    }

    public function test_role_downgrade_and_tenant_revocation_take_effect_on_next_request(): void
    {
        $company=$this->tenant('Immediate role updates');
        $user=$this->actor($company,'inventory_manager');
        $this->actingAs($user);
        $base=$this->root($company);

        $this->postJson($base.'/assets',[])->assertUnprocessable();
        $this->assertDatabaseHas('company_memberships',[
            'user_id'=>$user->id,'company_id'=>$company->id,'role_code'=>'inventory_manager',
        ]);

        // The server must not cache elevated permissions in the session.
        $user->companyMemberships()->where('company_id',$company->id)
            ->update(['role_code'=>'viewer']);
        $this->postJson($base.'/assets',[])->assertForbidden();
        $this->getJson($base.'/assets')->assertOk();

        $user->companyMemberships()->where('company_id',$company->id)->delete();
        $this->getJson($base.'/assets')->assertNotFound();
        $this->getJson($base.'/reports/summary')->assertNotFound();
        $this->getJson($base.'/members')->assertNotFound();
    }

    public function test_missing_authentication_blocks_all_protected_company_endpoints(): void
    {
        $company=$this->tenant('Unauthenticated tenant');
        $base=$this->root($company);
        foreach ([
            '/assets','/reports/summary','/inventory-sessions',
            '/facility-nodes','/members','/reports/forms/asset-register',
        ] as $endpoint) {
            $this->getJson($base.$endpoint)->assertUnauthorized();
        }
    }
}
