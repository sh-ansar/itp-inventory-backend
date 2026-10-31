<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class InventorySessionItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'session_id' => $this->session_id,
            'asset_id' => $this->asset_id,
            'inventory_number' => $this->inventory_number,
            'name' => $this->name,
            'expected_location_id' => $this->expected_location_id,
            'expected_location_name' => $this->expected_location_name,
            'expected_facility_node_id' => $this->expected_facility_node_id,
            'expected_facility_node_name' => $this->expected_facility_node_name,
            'actual_facility_node_id' => $this->actual_facility_node_id,
            'actual_facility_node_name' => $this->actual_facility_node_name,
            'actual_location_id' => $this->actual_location_id,
            'actual_location_name' => $this->actual_location_name,
            'is_counted' => $this->is_counted,
            'is_discrepancy' => $this->is_counted
                && ($this->expected_facility_node_id !== null
                    ? $this->actual_facility_node_id !== $this->expected_facility_node_id
                    : $this->actual_location_id !== $this->expected_location_id),
            'version' => $this->version,
            'note' => $this->note,
            'counted_by_name' => $this->counted_by_name,
            'counted_at' => $this->counted_at?->toIso8601String(),
        ];
    }
}
