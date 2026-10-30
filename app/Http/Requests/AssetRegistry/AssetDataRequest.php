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
        foreach (['name','inventory_number','serial_number','description'] as $field) {
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
        if ($ignoreId) $unique->ignore($ignoreId, 'id');

        return [
            'inventory_number' => ['required', 'string', 'min:1', 'max:100', $unique],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:3000'],
            'category_id' => ['nullable', 'uuid', Rule::exists('asset_categories','id')->where('company_id',$company->id)],
            'accounting_location_id' => ['nullable', 'uuid',
                Rule::exists('asset_locations','id')->where(fn ($q) =>
                    $q->where('company_id',$company->id)->where('kind','accounting'))],
            'physical_location_id' => ['nullable', 'uuid',
                Rule::exists('asset_locations','id')->where(fn ($q) =>
                    $q->where('company_id',$company->id)->where('kind','physical'))],
        ];
    }
}