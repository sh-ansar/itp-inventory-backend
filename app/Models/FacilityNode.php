<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class FacilityNode extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'code', 'name', 'node_type', 'address', 'floor_number',
        'parent_id', 'responsible_person_id', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['version' => 'integer', 'sort_order' => 'integer', 'floor_number' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function responsiblePerson(): BelongsTo
    {
        return $this->belongsTo(ResponsiblePerson::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'facility_node_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(FacilityNodeEvent::class);
    }
}
