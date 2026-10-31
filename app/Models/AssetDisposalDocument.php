<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AssetDisposalDocument extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public const UPDATED_AT = null;

    protected $fillable = [
        'id', 'company_id', 'disposal_id', 'original_name', 'storage_path',
        'mime_type', 'file_size', 'uploaded_by', 'uploaded_by_name',
    ];

    protected function casts(): array
    {
        return ['file_size' => 'integer'];
    }

    public function disposal(): BelongsTo
    {
        return $this->belongsTo(AssetDisposal::class, 'disposal_id');
    }
}
