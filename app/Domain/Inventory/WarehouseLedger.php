<?php

namespace App\Domain\Inventory;

use App\Models\Company;
use App\Models\StockBalance;
use App\Models\StockTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WarehouseLedger
{
    public const UNITS = ['pcs', 'pack', 'kg', 'm', 'l'];

    public const OPERATIONS = ['receipt', 'issue', 'transfer'];

    /** Convert a validated decimal to exact milliunits; do not use floating point. */
    public static function milli(string $value, string $unit, bool $allowZero = false): int
    {
        if (! preg_match('/\A([0-9]{1,6})(?:\.([0-9]{1,3}))?\z/D', $value, $parts)) {
            throw ValidationException::withMessages(['quantity' => ['Quantity must be positive with up to 3 decimals']]);
        }
        $integer = (int) $parts[1];
        $fraction = (int) str_pad($parts[2] ?? '', 3, '0');
        $quantity = $integer * 1000 + $fraction;
        if ($quantity <= 0 && ! $allowZero) {
            throw ValidationException::withMessages(['quantity' => ['Quantity must be greater than zero']]);
        }
        if (in_array($unit, ['pcs', 'pack'], true) && $fraction !== 0) {
            throw ValidationException::withMessages(['quantity' => ['Whole pieces or packs are required']]);
        }

        return $quantity;
    }

    public function record(Company $company, User $actor, array $data): StockTransaction
    {
        return DB::transaction(function () use ($company, $actor, $data): StockTransaction {
            $item = $company->stockItems()->whereKey($data['stock_item_id'])
                ->lockForUpdate()->firstOrFail();
            abort_unless($item->is_active, 409, 'Stock item is inactive');
            $type = $data['operation'];
            $warehouseId = $data['warehouse_node_id'];
            $targetId = $data['target_warehouse_node_id'] ?? null;
            if ($type === 'transfer' && (! $targetId || $warehouseId === $targetId)) {
                throw ValidationException::withMessages([
                    'target_warehouse_node_id' => ['Choose a distinct destination warehouse'],
                ]);
            }
            if ($type !== 'transfer' && $targetId) {
                throw ValidationException::withMessages([
                    'target_warehouse_node_id' => ['Destination is only valid for transfers'],
                ]);
            }
            $warehouses = [$warehouseId];
            if ($targetId) {
                $warehouses[] = $targetId;
            }
            $warehouseCount = $company->facilityNodes()
                ->where('node_type', 'warehouse')->whereIn('id', $warehouses)->count();
            if ($warehouseCount !== count($warehouses)) {
                throw ValidationException::withMessages(['warehouse_node_id' => ['Choose company-owned warehouses']]);
            }
            $quantity = self::milli((string) $data['quantity'], $item->unit);
            $now = now();
            foreach ($warehouses as $warehouse) {
                DB::table('stock_balances')->insertOrIgnore([
                    'company_id' => $company->id, 'stock_item_id' => $item->id,
                    'warehouse_node_id' => $warehouse, 'quantity_milli' => 0,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            // Deterministic row-lock order avoids reciprocal transfer deadlocks.
            $balanceRows = StockBalance::query()
                ->where('company_id', $company->id)
                ->where('stock_item_id', $item->id)
                ->whereIn('warehouse_node_id', $warehouses)
                ->orderBy('warehouse_node_id')->lockForUpdate()->get()->keyBy('warehouse_node_id');
            $source = $type !== 'receipt' ? $balanceRows->get($warehouseId) : null;
            $destination = $type === 'receipt' ? $balanceRows->get($warehouseId) :
                ($type === 'transfer' ? $balanceRows->get($targetId) : null);
            if (($source && $source->quantity_milli < $quantity)) {
                throw ValidationException::withMessages(['quantity' => ['Insufficient warehouse stock']]);
            }
            $beforeSource = $source?->quantity_milli;
            $beforeDestination = $destination?->quantity_milli;
            if ($source) {
                $source->quantity_milli -= $quantity;
                $source->save();
            }
            if ($destination) {
                $destination->quantity_milli += $quantity;
                $destination->save();
            }

            return $company->stockTransactions()->create([
                'id' => (string) Str::uuid(),
                'stock_item_id' => $item->id, 'operation' => $type,
                'source_warehouse_node_id' => $source?->warehouse_node_id,
                'destination_warehouse_node_id' => $destination?->warehouse_node_id,
                'quantity_milli' => $quantity,
                'before_source_milli' => $beforeSource, 'after_source_milli' => $source?->quantity_milli,
                'before_destination_milli' => $beforeDestination, 'after_destination_milli' => $destination?->quantity_milli,
                'actor_id' => $actor->getAuthIdentifier(), 'actor_name' => $actor->name,
                'reason' => trim($data['reason']),
            ]);
        }, 3);
    }
}
