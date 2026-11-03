<?php

namespace App\Domain\Inventory;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class IndustryCatalog
{
    public const KINDS = ['unit', 'manufacturer', 'model', 'department'];

    public const SYSTEM_UNITS = [
        'pcs' => ['name' => 'Pieces', 'precision' => 0],
        'pack' => ['name' => 'Packages', 'precision' => 0],
        'kg' => ['name' => 'Kilograms', 'precision' => 3],
        'm' => ['name' => 'Meters', 'precision' => 3],
        'l' => ['name' => 'Liters', 'precision' => 3],
    ];

    public static function normalized(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }

    public static function unitPrecision(Company $company, string $code): ?int
    {
        if (isset(self::SYSTEM_UNITS[$code])) {
            return self::SYSTEM_UNITS[$code]['precision'];
        }
        $value = DB::table('industry_catalog_items')
            ->where('company_id', $company->id)->where('kind', 'unit')
            ->where('code', $code)->where('is_active', true)->value('precision');

        return $value === null ? null : (int) $value;
    }

    public static function validateQuantityPrecision(
        Company $company, string $code, string $value, string $field = 'quantity'
    ): void {
        $precision = self::unitPrecision($company, $code);
        if ($precision === null) {
            throw ValidationException::withMessages(['unit' => ['Unit is not active in this company']]);
        }
        if ($precision === 0 && preg_match('/\.([0-9]{1,3})\z/', $value, $match)
            && (int) $match[1] !== 0) {
            throw ValidationException::withMessages([$field => ['Whole units required']]);
        }
    }
}
