<?php

namespace Tests\Unit;

use App\Domain\Inventory\CompanyRole;
use PHPUnit\Framework\TestCase;

final class CompanyRoleTest extends TestCase
{
    public function test_all_allowed_roles_are_stable_and_unique(): void
    {
        $this->assertSame(['company_admin', 'inventory_manager', 'viewer'], CompanyRole::values());
        $this->assertCount(3, array_unique(CompanyRole::values()));
    }

    public function test_write_and_administration_have_explicit_boundaries(): void
    {
        foreach (CompanyRole::cases() as $role) {
            $this->assertSame($role !== CompanyRole::Viewer, $role->canWrite());
            $this->assertSame($role === CompanyRole::Admin, $role->canAdminister());
        }

        $this->assertNull(CompanyRole::tryFrom('portal_admin'));
        $this->assertNull(CompanyRole::tryFrom(''));
    }
}
