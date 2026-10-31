<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class InventorySessionItem extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'session_id', 'asset_id', 'inventory_number',
        'name', 'expected_location_id', 'expected_location_name',
        'expected_facility_node_id', 'expected_facility_node_name',
        'actual_facility_node_id', 'actual_facility_node_name',
        'actual_location_id', 'actual_location_name', 'is_counted',
        'version', 'note', 'counted_by', 'counted_by_name', 'counted_at',
    ];

    protected function casts(): array
    {
        return [
            'is_counted' => 'boolean',
            'version' => 'integer',
            'counted_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(InventorySession::class, 'session_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class)->withTrashed();
    }
}
