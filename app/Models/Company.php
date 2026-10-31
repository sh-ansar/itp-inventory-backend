<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Company extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'name'];

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function assetCategories(): HasMany
    {
        return $this->hasMany(AssetCategory::class);
    }

    public function assetLocations(): HasMany
    {
        return $this->hasMany(AssetLocation::class);
    }

    public function inventorySessions(): HasMany
    {
        return $this->hasMany(InventorySession::class);
    }

    public function facilityNodes(): HasMany
    {
        return $this->hasMany(FacilityNode::class);
    }

    public function facilityPlans(): HasMany
    {
        return $this->hasMany(FacilityPlan::class);
    }

    public function assetDisposalCases(): HasMany
    {
        return $this->hasMany(AssetDisposalCase::class);
    }

    public function responsiblePeople(): HasMany
    {
        return $this->hasMany(ResponsiblePerson::class);
    }

    public function stockItems(): HasMany
    {
        return $this->hasMany(StockItem::class);
    }

    public function stockTransactions(): HasMany
    {
        return $this->hasMany(StockTransaction::class);
    }
}
