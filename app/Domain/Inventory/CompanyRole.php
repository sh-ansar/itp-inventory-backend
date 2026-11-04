<?php

namespace App\Domain\Inventory;

/**
 * Inventory-only tenancy roles. Deliberately not shared with Portal/HRM:
 * those systems have separate accounts, permissions and session boundaries.
 */
enum CompanyRole: string
{
    case Admin = 'company_admin';
    case Manager = 'inventory_manager';
    case Viewer = 'viewer';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }

    public function canWrite(): bool
    {
        return $this === self::Admin || $this === self::Manager;
    }

    public function canAdminister(): bool
    {
        return $this === self::Admin;
    }
}
