<?php

namespace App\Http\Requests\AssetRegistry;

use App\Domain\Inventory\CompanyAccess;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

final class ArchiveAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');
        abort_unless($company instanceof Company, 404);
        app(CompanyAccess::class)->canArchive($this, $company);
        return true;
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}