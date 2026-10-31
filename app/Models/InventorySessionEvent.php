<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class InventorySessionEvent extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    public const CREATED_AT = 'created_at';

    protected $fillable = [
        'id', 'company_id', 'session_id', 'item_id',
        'event_type', 'version', 'actor_id', 'actor_name',
        'before', 'after', 'reason',
    ];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'version' => 'integer', 'created_at' => 'datetime'];
    }
}
