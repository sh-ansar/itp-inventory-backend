<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class InventorySessionEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'session_id' => $this->session_id,
            'item_id' => $this->item_id,
            'event_type' => $this->event_type,
            'version' => $this->version,
            'actor_name' => $this->actor_name,
            'before' => $this->before,
            'after' => $this->after,
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
