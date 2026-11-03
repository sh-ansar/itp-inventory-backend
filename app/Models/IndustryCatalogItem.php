<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class IndustryCatalogItem extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id','company_id','kind','code','name','normalized_name','parent_id',
        'parent_key','source','external_id','precision','is_active','version',
    ];

    protected function casts(): array
    {
        return ['precision' => 'integer', 'is_active' => 'boolean', 'version' => 'integer'];
    }
}
