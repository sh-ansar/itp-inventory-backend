<?php

namespace App\Domain\Inventory;

use App\Models\Company;
use App\Models\FacilityNode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class FacilityHierarchy
{
    public const TYPES = [
        'site', 'building', 'pavilion', 'floor', 'room', 'warehouse', 'zone', 'shelf',
    ];

    private const CHILDREN = [
        'site' => ['building', 'pavilion', 'warehouse', 'zone'],
        'building' => ['floor', 'room', 'warehouse', 'zone'],
        'pavilion' => ['floor', 'room', 'zone'],
        'floor' => ['room', 'pavilion', 'zone'],
        'room' => ['zone', 'shelf'],
        'warehouse' => ['zone', 'shelf'],
        'zone' => ['zone', 'shelf'],
        'shelf' => [],
    ];

    private const ROOT_TYPES = ['site', 'building', 'warehouse'];

    public function descendants(Company $company, string $rootId, bool $includeSelf = true): array
    {
        $nodes = $company->facilityNodes()->get(['id', 'parent_id']);
        $ids = [];
        $seen = [$rootId => true];
        if ($includeSelf) {
            $ids[] = $rootId;
        }
        $pending = [$rootId];
        $iterations = 0;
        do {
            if (++$iterations > 10) {
                abort(409, 'Facility tree too deep or circular');
            }
            $next = [];
            foreach ($nodes as $node) {
                if (isset($seen[$node->id]) || ! in_array($node->parent_id, $pending, true)) {
                    continue;
                }
                $seen[$node->id] = true;
                $next[] = $node->id;
                $ids[] = $node->id;
            }
            $pending = $next;
        } while ($pending);

        return $ids;
    }

    public function validatePlacement(
        Company $company, string $type, ?string $parentId,
        ?string $movingNodeId = null
    ): void {
        if (! in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['node_type' => ['Unsupported facility node type']]);
        }
        if (! $parentId) {
            if (! in_array($type, self::ROOT_TYPES, true)) {
                throw ValidationException::withMessages(['parent_id' => ['Only site, building or warehouse may be a root']]);
            }

            return;
        }
        $parent = $company->facilityNodes()->whereKey($parentId)->firstOrFail();
        if ($movingNodeId) {
            if ($movingNodeId === $parentId
                || in_array($parentId, $this->descendants($company, $movingNodeId, false), true)) {
                throw ValidationException::withMessages(['parent_id' => ['A node cannot be moved into itself or its descendants']]);
            }
        }
        if (! in_array($type, self::CHILDREN[$parent->node_type] ?? [], true)) {
            throw ValidationException::withMessages(['node_type' => ['This type cannot be placed under the selected parent']]);
        }
        // Hard depth limit to prevent complex/circular structures and accidental UI hangs.
        $visited = [];
        $current = $parent;
        $depth = 1;
        while ($current->parent_id) {
            if (isset($visited[$current->id]) || ++$depth > 7) {
                throw ValidationException::withMessages(['parent_id' => ['Maximum facility nesting depth exceeded']]);
            }
            $visited[$current->id] = true;
            $current = $company->facilityNodes()->whereKey($current->parent_id)->firstOrFail();
        }
        if ($movingNodeId) {
            $subtree = $this->descendants($company, $movingNodeId);
            // The moved subtree's depth must also fit after reparenting.
            $descendants = $company->facilityNodes()->whereIn('id', $subtree)->get(['id', 'parent_id']);
            $level = [$movingNodeId => 1];
            for ($i = 0; $i < 8; $i++) {
                foreach ($descendants as $node) {
                    if (! isset($level[$node->id]) && isset($level[$node->parent_id])) {
                        $level[$node->id] = $level[$node->parent_id] + 1;
                    }
                }
            }
            if ($depth + max($level) > 8) {
                throw ValidationException::withMessages(['parent_id' => ['Moved subtree would exceed maximum depth']]);
            }
        }
    }

    public function snapshot(FacilityNode $node): array
    {
        return $node->only([
            'parent_id', 'name', 'code', 'node_type', 'address', 'floor_number',
            'responsible_person_id', 'sort_order', 'version',
        ]);
    }

    public function event(
        FacilityNode $node, User $actor, string $type,
        ?array $before, array $after, ?string $reason = null
    ): void {
        $node->events()->create([
            'id' => (string) Str::uuid(),
            'company_id' => $node->company_id,
            'event_type' => $type,
            'version' => $node->version,
            'actor_id' => $actor->getAuthIdentifier(),
            'actor_name' => $actor->name,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
        ]);
    }

    public function create(Company $company, User $actor, array $data): FacilityNode
    {
        return DB::transaction(function () use ($company, $actor, $data): FacilityNode {
            $this->validatePlacement($company, $data['node_type'], $data['parent_id'] ?? null);
            $node = $company->facilityNodes()->create([
                'id' => (string) Str::uuid(), ...$data,
            ]);
            $node->refresh();
            $this->event($node, $actor, 'created', null, $this->snapshot($node));

            return $node;
        }, 3);
    }

    public function update(Company $company, string $id, User $actor, array $data): FacilityNode
    {
        return DB::transaction(function () use ($company, $id, $actor, $data): FacilityNode {
            $node = $company->facilityNodes()->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($node->version === $data['version'], 409, 'Facility was modified; reload');
            $before = $this->snapshot($node);
            $type = $data['node_type'] ?? $node->node_type;
            $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $node->parent_id;
            $this->validatePlacement($company, $type, $parentId, $node->id);

            if ($type !== $node->node_type && $node->children()->exists()) {
                throw ValidationException::withMessages([
                    'node_type' => ['Cannot change type while children exist'],
                ]);
            }
            $node->fill(collect($data)->except('version')->all());
            if ($node->isDirty()) {
                $node->version++;
                $node->save();
                $this->event($node, $actor, 'updated', $before, $this->snapshot($node));
            }

            return $node->refresh();
        }, 3);
    }
}
