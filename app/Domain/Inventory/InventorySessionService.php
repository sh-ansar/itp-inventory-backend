<?php

namespace App\Domain\Inventory;

use App\Models\Company;
use App\Models\InventorySession;
use App\Models\InventorySessionItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InventorySessionService
{
    private function audit(
        InventorySession $session,
        User $actor,
        string $eventType,
        ?array $before,
        ?array $after,
        ?InventorySessionItem $item = null,
        ?string $reason = null
    ): void {
        $session->events()->create([
            'id' => (string) Str::uuid(),
            'company_id' => $session->company_id,
            'item_id' => $item?->id,
            'event_type' => $eventType,
            'version' => $session->version,
            'actor_id' => $actor->getAuthIdentifier(),
            'actor_name' => $actor->name,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
        ]);
    }

    /**
     * Freeze the active asset set within one company.
     * Every snapshot is immutable; later asset moves do not silently rewrite expectations.
     */
    public function start(Company $company, User $actor, array $data): InventorySession
    {
        return DB::transaction(function () use ($company, $actor, $data): InventorySession {
            $location = ! empty($data['scope_location_id'])
                ? $company->assetLocations()->where('kind', 'physical')
                    ->whereKey($data['scope_location_id'])->firstOrFail()
                : null;
            $selected = $data['asset_ids'] ?? null;
            $facility = ! empty($data['facility_node_id'])
                ? $company->facilityNodes()->whereKey($data['facility_node_id'])->firstOrFail() : null;
            if ($facility && $location) {
                throw ValidationException::withMessages(['facility_node_id' => ['Choose either a facility or a physical location']]);
            }
            $scopeType = $selected ? 'selected' : ($facility ? 'facility' : ($location ? 'location' : 'all'));

            $session = $company->inventorySessions()->create([
                'id' => (string) Str::uuid(),
                'name' => trim($data['name']),
                'scope_type' => $scopeType,
                'scope_location_id' => $location?->id,
                'scope_location_name' => $location?->name,
                'scope_facility_node_id' => $facility?->id,
                'scope_facility_node_name' => $facility?->name,
                'status' => 'in_progress',
                'version' => 1,
                'total_items' => 0,
                'counted_items' => 0,
                'discrepancies' => 0,
                'created_by' => $actor->getAuthIdentifier(),
                'created_by_name' => $actor->name,
            ]);

            $query = $company->assets()->where('status', 'active')
                ->with(['physicalLocation', 'facilityNode']);
            if ($facility) {
                $descendantIds = app(FacilityHierarchy::class)->descendants($company, $facility->id);
                $query->whereIn('facility_node_id', $descendantIds);
            }
            if ($location) {
                $query->where('physical_location_id', $location->id);
            }
            if ($selected) {
                $query->whereIn('id', $selected);
            }

            $total = 0;
            // Chunk without forcing the whole company into memory. Asset status/location
            // may evolve later; each row captures the start-time name and location.
            $query->chunkById(250, function ($assets) use ($session, $company, &$total): void {
                $now = now();
                $records = [];
                foreach ($assets as $asset) {
                    $records[] = [
                        'id' => (string) Str::uuid(),
                        'company_id' => $company->id,
                        'session_id' => $session->id,
                        'asset_id' => $asset->id,
                        'inventory_number' => $asset->inventory_number,
                        'name' => $asset->name,
                        'expected_location_id' => $asset->physical_location_id,
                        'expected_location_name' => $asset->physicalLocation?->name,
                        'expected_facility_node_id' => $asset->facility_node_id,
                        'expected_facility_node_name' => $asset->facilityNode?->name,
                        'is_counted' => false,
                        'version' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if ($records) {
                    InventorySessionItem::query()->insert($records);
                    $total += count($records);
                }
            });

            if ($total === 0) {
                throw ValidationException::withMessages([
                    'asset_ids' => ['No active company assets in the selected inventory scope'],
                ]);
            }
            if ($selected && $total !== count($selected)) {
                throw ValidationException::withMessages([
                    'asset_ids' => ['Some assets are missing, archived, or outside the selected company/scope'],
                ]);
            }
            $session->total_items = $total;
            $session->save();

            $this->audit($session, $actor, 'created', null, [
                'status' => 'in_progress',
                'name' => $session->name,
                'scope_type' => $session->scope_type,
                'scope_location_id' => $location?->id,
                'scope_facility_node_id' => $facility?->id,
                'total_items' => $total,
                'version' => $session->version,
            ]);

            return $session->refresh();
        }, 3);
    }

    /**
     * Lock session before item so concurrent close and recounts are serialized.
     * Version belongs to the individual item, allowing parallel counters.
     */
    public function countItem(
        Company $company,
        string $sessionId,
        string $itemId,
        User $actor,
        array $data,
        bool $allowRecount = true
    ): array {
        return DB::transaction(function () use (
            $company, $sessionId, $itemId, $actor, $data, $allowRecount
        ): array {
            $session = $company->inventorySessions()->whereKey($sessionId)
                ->lockForUpdate()->firstOrFail();
            abort_unless($session->status === 'in_progress', 409, 'Inventory is completed');

            $item = $session->items()->whereKey($itemId)
                ->lockForUpdate()->firstOrFail();
            abort_unless($item->version === $data['version'], 409, 'Inventory item changed; reload');
            if ($item->is_counted && ! $allowRecount) {
                abort(409, 'This asset was already counted');
            }

            $reason = isset($data['reason']) ? trim((string) $data['reason']) : null;
            if ($item->is_counted && (! $reason || mb_strlen($reason) < 5)) {
                throw ValidationException::withMessages([
                    'reason' => ['A reason (5+ characters) is required for recounting'],
                ]);
            }
            $facilityMode = $item->expected_facility_node_id !== null;
            if ($facilityMode && empty($data['actual_facility_node_id'])) {
                throw ValidationException::withMessages([
                    'actual_facility_node_id' => ['Select actual facility or room for this mapped asset'],
                ]);
            }
            if (! $facilityMode && empty($data['actual_location_id'])) {
                throw ValidationException::withMessages([
                    'actual_location_id' => ['Select a physical location for this asset'],
                ]);
            }
            $actualFacility = $facilityMode
                ? $company->facilityNodes()->whereKey($data['actual_facility_node_id'])->firstOrFail()
                : null;
            $actualLocation = ! $facilityMode
                ? $company->assetLocations()->where('kind', 'physical')
                    ->whereKey($data['actual_location_id'])->firstOrFail()
                : null;
            $before = [
                'is_counted' => $item->is_counted,
                'actual_location_id' => $item->actual_location_id,
                'actual_location_name' => $item->actual_location_name,
                'actual_facility_node_id' => $item->actual_facility_node_id,
                'actual_facility_node_name' => $item->actual_facility_node_name,
                'note' => $item->note,
                'version' => $item->version,
            ];
            $wasDiscrepancy = $item->is_counted && (
                $facilityMode
                    ? $item->actual_facility_node_id !== $item->expected_facility_node_id
                    : $item->actual_location_id !== $item->expected_location_id
            );
            $newDiscrepancy = $facilityMode
                ? $actualFacility->id !== $item->expected_facility_node_id
                : $actualLocation->id !== $item->expected_location_id;

            if (! $item->is_counted) {
                $session->counted_items++;
            }
            $session->discrepancies += (int) $newDiscrepancy - (int) $wasDiscrepancy;
            $session->version++;
            $session->save();

            $type = $item->is_counted ? 'recounted' : 'counted';
            $item->is_counted = true;
            $item->actual_location_id = $actualLocation?->id;
            $item->actual_location_name = $actualLocation?->name;
            $item->actual_facility_node_id = $actualFacility?->id;
            $item->actual_facility_node_name = $actualFacility?->name;
            $item->note = isset($data['note']) ? trim((string) $data['note']) : null;
            $item->counted_at = now();
            $item->counted_by = $actor->getAuthIdentifier();
            $item->counted_by_name = $actor->name;
            $item->version++;
            $item->save();

            $after = [
                'is_counted' => true,
                'actual_location_id' => $actualLocation?->id,
                'actual_location_name' => $actualLocation?->name,
                'actual_facility_node_id' => $actualFacility?->id,
                'actual_facility_node_name' => $actualFacility?->name,
                'note' => $item->note,
                'version' => $item->version,
            ];
            $this->audit($session, $actor, $type, $before, $after, $item, $reason);

            return [$session->refresh(), $item->refresh()];
        }, 3);
    }

    public function finish(Company $company, string $sessionId, User $actor, int $version): InventorySession
    {
        return DB::transaction(function () use ($company, $sessionId, $actor, $version): InventorySession {
            $session = $company->inventorySessions()->whereKey($sessionId)
                ->lockForUpdate()->firstOrFail();
            abort_unless($session->status === 'in_progress', 409, 'Inventory is already completed');
            abort_unless($session->version === $version, 409, 'Inventory changed; reload');

            $before = $this->summary($session);
            $session->status = 'completed';
            $session->completed_by = $actor->getAuthIdentifier();
            $session->completed_by_name = $actor->name;
            $session->completed_at = now();
            $session->version++;
            $session->save();

            $this->audit($session, $actor, 'completed', $before, $this->summary($session));

            return $session->refresh();
        }, 3);
    }

    public function reopen(
        Company $company, string $sessionId, User $actor, int $version, string $reason
    ): InventorySession {
        return DB::transaction(function () use ($company, $sessionId, $actor, $version, $reason): InventorySession {
            $session = $company->inventorySessions()->whereKey($sessionId)
                ->lockForUpdate()->firstOrFail();
            abort_unless($session->status === 'completed', 409, 'Inventory is not completed');
            abort_unless($session->version === $version, 409, 'Inventory changed; reload');

            $before = $this->summary($session);
            $session->status = 'in_progress';
            $session->completed_at = null;
            $session->completed_by = null;
            $session->completed_by_name = null;
            $session->version++;
            $session->save();

            $this->audit($session, $actor, 'reopened', $before, $this->summary($session), null, trim($reason));

            return $session->refresh();
        }, 3);
    }

    private function summary(InventorySession $session): array
    {
        return [
            'status' => $session->status,
            'version' => $session->version,
            'total_items' => $session->total_items,
            'counted_items' => $session->counted_items,
            'missing_items' => $session->total_items - $session->counted_items,
            'discrepancies' => $session->discrepancies,
            'completed_at' => $session->completed_at?->toIso8601String(),
            'completed_by_name' => $session->completed_by_name,
        ];
    }
}
