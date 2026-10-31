<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'inventory_number' => $this->inventory_number,
            'name' => $this->name,
            'serial_number' => $this->serial_number,
            'description' => $this->description,
            'status' => $this->status,
            'category_id' => $this->category_id,
            'category_name' => $this->whenLoaded('category', fn () => $this->category?->name),
            'accounting_location_id' => $this->accounting_location_id,
            'accounting_location_name' => $this->whenLoaded('accountingLocation', fn () => $this->accountingLocation?->name),
            'physical_location_id' => $this->physical_location_id,
            'physical_location_name' => $this->whenLoaded('physicalLocation', fn () => $this->physicalLocation?->name),
            'facility_node_id' => $this->facility_node_id,
            'facility_node_name' => $this->whenLoaded('facilityNode', fn () => $this->facilityNode?->name),
            'responsible_person_id' => $this->responsible_person_id,
            'responsible_person_name' => $this->whenLoaded('responsiblePerson', fn () => $this->responsiblePerson?->name),
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
