<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class InventorySessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'name' => $this->name,
            'status' => $this->status,
            'scope_type' => $this->scope_type,
            'scope_location_id' => $this->scope_location_id,
            'scope_location_name' => $this->scope_location_name,
            'scope_facility_node_id' => $this->scope_facility_node_id,
            'scope_facility_node_name' => $this->scope_facility_node_name,
            'total_items' => $this->total_items,
            'counted_items' => $this->counted_items,
            'missing_items' => $this->total_items - $this->counted_items,
            'discrepancies' => $this->discrepancies,
            'version' => $this->version,
            'created_by_name' => $this->created_by_name,
            'completed_by_name' => $this->completed_by_name,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
