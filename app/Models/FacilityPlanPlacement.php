<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FacilityPlanPlacement extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'facility_plan_id', 'asset_id', 'child_node_id',
        'x', 'y', 'width', 'height',
    ];

    protected function casts(): array
    {
        return ['x' => 'integer', 'y' => 'integer', 'width' => 'integer', 'height' => 'integer'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function childNode(): BelongsTo
    {
        return $this->belongsTo(FacilityNode::class, 'child_node_id');
    }
}
