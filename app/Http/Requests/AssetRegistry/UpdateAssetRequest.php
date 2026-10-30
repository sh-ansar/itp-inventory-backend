<?php

namespace App\Http\Requests\AssetRegistry;

final class UpdateAssetRequest extends AssetDataRequest
{
    public function rules(): array
    {
        return [
            ...$this->assetRules($this->route('asset')),
            'version' => ['required', 'integer', 'min:1'],
        ];
    }
}