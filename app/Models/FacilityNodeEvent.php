<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class FacilityNodeEvent extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    protected $fillable = [
        'id', 'company_id', 'facility_node_id', 'event_type',
        'version', 'actor_id', 'actor_name', 'before', 'after', 'reason',
    ];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'version' => 'integer', 'created_at' => 'datetime'];
    }
}
