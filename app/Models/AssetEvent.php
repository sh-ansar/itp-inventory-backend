<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AssetEvent extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public const UPDATED_AT = null;
    protected $fillable = [
        'id', 'company_id', 'asset_id', 'actor_id', 'actor_name',
        'event_type', 'version', 'before', 'after', 'reason',
    ];
    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'version' => 'integer'];
    }
}