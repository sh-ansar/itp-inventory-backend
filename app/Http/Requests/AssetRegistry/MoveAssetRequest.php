<?php

namespace App\Http\Requests\AssetRegistry;

use App\Domain\Inventory\CompanyAccess;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class MoveAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');
        abort_unless($company instanceof Company, 404);
        app(CompanyAccess::class)->canWrite($this, $company);
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    public function rules(): array
    {
        $company = $this->route('company');
        return [
            'version' => ['required', 'integer', 'min:1'],
            'to_location_id' => [
                'required', 'uuid',
                Rule::exists('asset_locations', 'id')
                    ->where('company_id', $company->id)
                    ->where('kind', 'physical'),
            ],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}