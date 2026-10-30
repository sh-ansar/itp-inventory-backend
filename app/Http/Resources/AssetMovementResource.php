<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AssetMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'from_location_id' => $this->from_location_id,
            'to_location_id' => $this->to_location_id,
            'from_location_name' => $this->from_location_name,
            'to_location_name' => $this->to_location_name,
            'actor_name' => $this->actor_name,
            'reason' => $this->reason,
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}