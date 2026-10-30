<?php

namespace App\Domain\Inventory;

use App\Models\Asset;
use App\Models\AssetEvent;
use App\Models\User;
use Illuminate\Support\Str;

final class AssetAuditWriter
{
    public function record(
        Asset $asset, User $actor, string $eventType,
        ?array $before, ?array $after, ?string $reason = null
    ): void {
        AssetEvent::query()->create([
            'id' => (string) Str::uuid(),
            'company_id' => $asset->company_id,
            'asset_id' => $asset->id,
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'event_type' => $eventType,
            'version' => $asset->version,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
        ]);
    }
}