<?php

namespace App\Services\Commercial;

use App\Models\CompanyMembership;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class Access
{
    public const PERMISSIONS = [
        'platform.manage', 'companies.read', 'users.write',
        'plans.read', 'plans.write', 'subscriptions.write',
        'billing.read', 'billing.write', 'site.read', 'site.write', 'legal.read',
        'legal.write', 'legal.approve', 'legal.publish',
    ];

    public static function permissions(Request $request): array
    {
        $userId = $request->user()?->id;
        if (!$userId) return [];
        return DB::table('inventory_platform_grants')
            ->where('user_id', $userId)->pluck('permission')->all();
    }

    public static function need(Request $request, string $permission): void
    {
        $permissions = self::permissions($request);
        abort_unless(in_array('platform.manage', $permissions, true)
            || in_array($permission, $permissions, true), 403);
    }

    public static function company(Request $request, string $companyId, bool $write = false): CompanyMembership
    {
        $membership = $request->user()?->companyMemberships()
            ->where('company_id', $companyId)->first();
        abort_unless($membership, 403);
        if ($write) abort_unless($membership->role_code === 'company_admin', 403);
        return $membership;
    }

    public static function audit(?int $actor, ?string $company, string $action, string $subject, array $meta = []): void
    {
        DB::table('inventory_commercial_audit')->insert([
            'actor_id' => $actor,
            'company_id' => $company,
            'action' => $action,
            'subject' => $subject,
            'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
