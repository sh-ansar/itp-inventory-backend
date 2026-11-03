<?php

namespace App\Http\Requests\AssetRegistry;

use App\Domain\Inventory\CompanyAccess;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class AssetDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(CompanyAccess::class)->canWrite($this, $this->company());

        return true;
    }

    protected function prepareForValidation(): void
    {
        $fields = [];
        foreach (['name', 'inventory_number', 'serial_number', 'description'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $fields[$field] = trim($this->input($field));
            }
        }
        $this->merge($fields);
    }

    protected function company(): Company
    {
        $company = $this->route('company');
        abort_unless($company instanceof Company, 404);

        return $company;
    }

    protected function assetRules(?string $ignoreId = null): array
    {
        $company = $this->company();
        $unique = Rule::unique('assets', 'inventory_number')
            ->where('company_id', $company->id);
        if ($ignoreId) {
            $unique->ignore($ignoreId, 'id');
        }
        // Existing assets may keep their historical archived references, but
        // no new asset or reassignment may select an archived dictionary value.
        $current = $ignoreId ? $company->assets()->whereKey($ignoreId)->first() : null;
        $referenceRule = function (string $table, string $field, ?string $kind = null) use ($company, $current) {
            return Rule::exists($table, 'id')->where(function ($query) use ($company, $current, $field, $kind) {
                $query->where('company_id', $company->id);
                if ($kind !== null) $query->where('kind', $kind);
                $query->where(function ($q) use ($current, $field) {
                    $q->where('is_active', true);
                    if ($current?->{$field}) $q->orWhere('id', $current->{$field});
                });
            });
        };

        return [
            'inventory_number' => ['required', 'string', 'min:1', 'max:100', $unique],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:3000'],
            'category_id' => ['nullable', 'uuid', $referenceRule('asset_categories', 'category_id')],
            'facility_node_id' => ['nullable', 'uuid', Rule::exists('facility_nodes', 'id')->where('company_id', $company->id)],
            'responsible_person_id' => ['nullable', 'uuid', Rule::exists('responsible_people', 'id')
                ->where('company_id', $company->id)->where('is_active', true)],
            'accounting_location_id' => ['nullable', 'uuid',
                $referenceRule('asset_locations', 'accounting_location_id', 'accounting'))],
            'physical_location_id' => ['nullable', 'uuid',
                $referenceRule('asset_locations', 'physical_location_id', 'physical'))],
        ];
    }
}
