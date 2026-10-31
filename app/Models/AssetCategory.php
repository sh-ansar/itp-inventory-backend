<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AssetCategory extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'code', 'name', 'normalized_name', 'icon_key', 'icon_path', 'base_key', 'is_base'];

    protected function casts(): array
    {
        return ['is_base' => 'boolean', 'version' => 'integer'];
    }
}
