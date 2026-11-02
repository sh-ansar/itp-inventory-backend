<?php

namespace App\Domain\Inventory;

use App\Models\Company;
use Illuminate\Validation\Rule;

/**
 * A common, tenant-scoped multi-filter contract across registry, Excel and reports.
 * Values within a facet are OR-ed, facets are AND-ed; [] means no restriction.
 * Legacy scalar filters still work for existing integrations and bookmarks.
 */
final class MultiAssetFilters
{
    public const MAX_SELECTED = 30;

    public static function rules(Company $company): array
    {
        return [
            'statuses' => ['sometimes', 'array', 'max:30'],
            'statuses.*' => ['required', 'string', 'distinct', Rule::in(['active', 'written_off', 'disposed'])],
            'facility_node_ids' => ['sometimes', 'array', 'max:30'],
            'facility_node_ids.*' => ['required', 'uuid', 'distinct',
                Rule::exists('facility_nodes', 'id')->where('company_id', $company->id)],
            'responsible_person_ids' => ['sometimes', 'array', 'max:30'],
            'responsible_person_ids.*' => ['required', 'uuid', 'distinct',
                Rule::exists('responsible_people', 'id')->where('company_id', $company->id)],
        ];
    }

    public static function apply(
        $query, Company $company, array $filters,
        bool $descendants = false, string $prefix = ''
    ): void {
        $statuses = self::selected($filters, 'statuses', 'status');
        $facilities = self::selected($filters, 'facility_node_ids', 'facility_node_id');
        $people = self::selected($filters, 'responsible_person_ids', 'responsible_person_id');

        if ($statuses !== []) $query->whereIn($prefix.'status', $statuses);
        if ($facilities !== []) {
            if ($descendants) {
                $hierarchy = app(FacilityHierarchy::class);
                $ids = [];
                foreach ($facilities as $id) {
                    foreach ($hierarchy->descendants($company, $id) as $node) $ids[$node] = true;
                }
                $facilities = array_keys($ids);
            }
            $query->whereIn($prefix.'facility_node_id', $facilities);
        }
        if ($people !== []) $query->whereIn($prefix.'responsible_person_id', $people);
    }

    private static function selected(array $filters, string $plural, string $singular): array
    {
        if (array_key_exists($plural, $filters)) return $filters[$plural] ?: [];
        return !empty($filters[$singular]) ? [$filters[$singular]] : [];
    }
}
