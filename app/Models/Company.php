<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Company extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['id', 'name'];
    public function assets(): HasMany { return $this->hasMany(Asset::class); }
    public function assetCategories(): HasMany { return $this->hasMany(AssetCategory::class); }
    public function assetLocations(): HasMany { return $this->hasMany(AssetLocation::class); }
}