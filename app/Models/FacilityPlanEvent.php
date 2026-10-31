<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FacilityPlanEvent extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'facility_plan_id', 'event_type', 'version',
        'actor_id', 'actor_name', 'before', 'after',
    ];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime', 'version' => 'integer'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(FacilityPlan::class, 'facility_plan_id');
    }
}
