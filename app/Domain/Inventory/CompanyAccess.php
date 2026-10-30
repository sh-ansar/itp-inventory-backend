<?php

namespace App\Domain\Inventory;

use App\Models\Company;
use App\Models\CompanyMembership;
use Illuminate\Http\Request;

final class CompanyAccess
{
    public function member(Request $request, Company $company): CompanyMembership
    {
        // A non-member sees 404: do not reveal companies they do not belong to.
        return $request->user()->companyMemberships()
            ->where('company_id', $company->getKey())
            ->firstOrFail();
    }

    public function canWrite(Request $request, Company $company): void
    {
        $member = $this->member($request, $company);
        abort_unless(in_array($member->role_code, ['company_admin', 'inventory_manager'], true), 403);
    }

    public function canArchive(Request $request, Company $company): void
    {
        $member = $this->member($request, $company);
        abort_unless($member->role_code === 'company_admin', 403);
    }
}