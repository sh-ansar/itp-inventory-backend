<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Asset extends Model
{
    use SoftDeletes;

    public $incrementing = false;
    protected $keyType = 'string';

    // Tenant/identity/status/version must be set by application services, not request payloads.
    protected $fillable = [
        'id', 'public_id', 'inventory_number', 'name', 'serial_number',
        'description', 'category_id', 'accounting_location_id',
        'physical_location_id', 'status',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function category(): BelongsTo { return $this->belongsTo(AssetCategory::class, 'category_id'); }
    public function accountingLocation(): BelongsTo { return $this->belongsTo(AssetLocation::class, 'accounting_location_id'); }
    public function physicalLocation(): BelongsTo { return $this->belongsTo(AssetLocation::class, 'physical_location_id'); }
    public function events(): HasMany { return $this->hasMany(AssetEvent::class); }
    public function movements(): HasMany { return $this->hasMany(AssetMovement::class); }
}