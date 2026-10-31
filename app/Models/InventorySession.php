<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class InventorySession extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'name', 'status', 'scope_type',
        'scope_location_id', 'scope_location_name',
        'scope_facility_node_id', 'scope_facility_node_name',
        'total_items', 'counted_items', 'discrepancies', 'version',
        'created_by', 'created_by_name',
        'completed_by', 'completed_by_name', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'total_items' => 'integer', 'counted_items' => 'integer',
            'discrepancies' => 'integer', 'version' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventorySessionItem::class, 'session_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(InventorySessionEvent::class, 'session_id');
    }
}
