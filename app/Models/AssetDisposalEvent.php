<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AssetDisposalEvent extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'asset_disposal_case_id', 'event_type', 'version',
        'actor_id', 'actor_name', 'before', 'after', 'reason',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer', 'created_at' => 'datetime',
            'before' => 'array', 'after' => 'array',
        ];
    }

    public function disposalCase(): BelongsTo
    {
        return $this->belongsTo(AssetDisposalCase::class, 'asset_disposal_case_id');
    }
}
