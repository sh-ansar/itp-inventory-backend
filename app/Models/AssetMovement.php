<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AssetMovement extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public const UPDATED_AT = null;

    protected $fillable = [
        'id','company_id','asset_id','from_location_id','to_location_id',
        'from_location_name','to_location_name','actor_id','actor_name',
        'reason','version',
    ];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}