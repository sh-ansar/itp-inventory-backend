<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AssetDisposalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'inventory_number' => $this->whenLoaded('asset', fn () => $this->asset?->inventory_number),
            'asset_name' => $this->whenLoaded('asset', fn () => $this->asset?->name),
            'asset_status' => $this->whenLoaded('asset', fn () => $this->asset?->status),
            'stage' => $this->stage,
            'document_number' => $this->document_number,
            'document_date' => $this->document_date?->format('Y-m-d'),
            'reason' => $this->reason,
            'disposal_method' => $this->disposal_method,
            'actor_name' => $this->actor_name,
            'asset_version' => $this->asset_version,
            'created_at' => $this->created_at?->toIso8601String(),
            'documents' => $this->whenLoaded('documents', fn () =>
                $this->documents->map(fn ($doc) => [
                    'id' => $doc->id,
                    'original_name' => $doc->original_name,
                    'mime_type' => $doc->mime_type,
                    'file_size' => $doc->file_size,
                    'uploaded_by_name' => $doc->uploaded_by_name,
                    'created_at' => $doc->created_at?->toIso8601String(),
                ])->all()
            ),
        ];
    }
}
