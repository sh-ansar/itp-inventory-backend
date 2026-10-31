<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ResponsiblePerson extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'code', 'name', 'position', 'email',
        'source', 'external_employee_id', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'version' => 'integer'];
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function facilityNodes(): HasMany
    {
        return $this->hasMany(FacilityNode::class);
    }
}
