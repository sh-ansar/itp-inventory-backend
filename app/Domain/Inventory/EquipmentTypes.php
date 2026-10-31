<?php

namespace App\Domain\Inventory;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EquipmentTypes
{
    public const ICONS = [
        'box', 'boxes', 'monitor', 'laptop', 'printer', 'server', 'network', 'smartphone',
        'hard-drive', 'router', 'armchair', 'wrench', 'factory', 'cog', 'camera', 'car',
        'tablet', 'package', 'cable', 'projector', 'keyboard', 'mouse',
    ];

    public const PRESETS = [
        ['base_key' => 'computer', 'code' => 'EQ-COMPUTER', 'name' => 'Компьютеры', 'icon_key' => 'monitor'],
        ['base_key' => 'laptop', 'code' => 'EQ-LAPTOP', 'name' => 'Ноутбуки', 'icon_key' => 'laptop'],
        ['base_key' => 'monitor', 'code' => 'EQ-MONITOR', 'name' => 'Мониторы', 'icon_key' => 'monitor'],
        ['base_key' => 'printer', 'code' => 'EQ-PRINTER', 'name' => 'Принтеры и МФУ', 'icon_key' => 'printer'],
        ['base_key' => 'server', 'code' => 'EQ-SERVER', 'name' => 'Серверное оборудование', 'icon_key' => 'server'],
        ['base_key' => 'network', 'code' => 'EQ-NETWORK', 'name' => 'Сетевое оборудование', 'icon_key' => 'network'],
        ['base_key' => 'telephone', 'code' => 'EQ-PHONE', 'name' => 'Телефоны', 'icon_key' => 'smartphone'],
        ['base_key' => 'storage', 'code' => 'EQ-STORAGE', 'name' => 'Накопители и СХД', 'icon_key' => 'hard-drive'],
        ['base_key' => 'furniture', 'code' => 'EQ-FURNITURE', 'name' => 'Мебель', 'icon_key' => 'armchair'],
        ['base_key' => 'instrument', 'code' => 'EQ-TOOLS', 'name' => 'Инструменты', 'icon_key' => 'wrench'],
        ['base_key' => 'production', 'code' => 'EQ-MACHINE', 'name' => 'Производственное оборудование', 'icon_key' => 'factory'],
        ['base_key' => 'other', 'code' => 'EQ-OTHER', 'name' => 'Прочее имущество', 'icon_key' => 'package'],
    ];

    public static function normalize(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)));
    }

    public function ensurePresets(Company $company): array
    {
        return DB::transaction(function () use ($company): array {
            $added = [];
            foreach (self::PRESETS as $item) {
                $normalized = self::normalize($item['name']);
                $category = $company->assetCategories()->where('base_key', $item['base_key'])->first();
                if ($category) {
                    continue;
                }
                // Never duplicate an existing manually entered category name or code.
                $conflict = $company->assetCategories()
                    ->where(fn ($q) => $q->where('code', $item['code'])
                        ->orWhere('normalized_name', $normalized)
                        ->orWhereRaw('LOWER(name) = ?', [$normalized]))->first();
                if ($conflict) {
                    continue;
                }
                $category = $company->assetCategories()->create([
                    ...$item,
                    'id' => (string) Str::uuid(),
                    'normalized_name' => $normalized,
                    'is_base' => true,
                    'version' => 1,
                ]);
                $added[] = $category->id;
            }

            return $added;
        }, 3);
    }
}
