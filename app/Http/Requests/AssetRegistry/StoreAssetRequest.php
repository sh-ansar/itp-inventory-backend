<?php

namespace App\Http\Requests\AssetRegistry;

final class StoreAssetRequest extends AssetDataRequest
{
    public function rules(): array { return $this->assetRules(); }
}