<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class FacilityPlan extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'facility_node_id', 'canvas_width', 'canvas_height',
        'version', 'background_path',
    ];

    protected function casts(): array
    {
        return [
            'canvas_width' => 'integer', 'canvas_height' => 'integer', 'version' => 'integer',
        ];
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(FacilityNode::class, 'facility_node_id');
    }

    public function placements(): HasMany
    {
        return $this->hasMany(FacilityPlanPlacement::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(FacilityPlanEvent::class);
    }
}
