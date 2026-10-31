<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AssetDisposalDocument extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'company_id', 'asset_disposal_case_id', 'document_type',
        'original_name', 'disk_path', 'mime_type', 'bytes', 'sha256',
        'uploaded_by', 'uploaded_by_name',
    ];

    protected function casts(): array
    {
        return ['bytes' => 'integer', 'created_at' => 'datetime'];
    }

    public function disposalCase(): BelongsTo
    {
        return $this->belongsTo(AssetDisposalCase::class, 'asset_disposal_case_id');
    }
}
