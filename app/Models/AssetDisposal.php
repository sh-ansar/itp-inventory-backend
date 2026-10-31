<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class AssetDisposal extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public const UPDATED_AT = null;

    protected $fillable = [
        'id', 'company_id', 'asset_id', 'stage', 'document_number', 'document_date',
        'reason', 'disposal_method', 'actor_id', 'actor_name', 'asset_version',
    ];

    protected function casts(): array
    {
        return ['document_date' => 'date:Y-m-d', 'asset_version' => 'integer'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class)->withTrashed();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AssetDisposalDocument::class, 'disposal_id')
            ->orderBy('created_at')->orderBy('id');
    }
}
