<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class AssetDisposalCase extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'asset_id', 'status', 'reason',
        'order_number', 'order_date', 'disposal_act_number', 'disposal_date',
        'disposal_method', 'version',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'order_date' => 'date:Y-m-d',
            'disposal_date' => 'date:Y-m-d',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id')->withTrashed();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AssetDisposalDocument::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AssetDisposalEvent::class);
    }
}
