<?php

namespace App\Http\Controllers\Api;

use App\Domain\Inventory\CompanyAccess;
use App\Domain\Inventory\IndustryCatalog;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class IndustryCatalogController extends Controller
{
    private function kind(string $kind): string
    {
        abort_unless(in_array($kind, IndustryCatalog::KINDS, true), 404);
        return $kind;
    }

    public function index(Request $request, Company $company, string $kind, CompanyAccess $access): JsonResponse
    {
        $access->member($request, $company);
        $kind = $this->kind($kind);
        $query = $company->industryCatalogItems()->where('kind', $kind);
        if (! $request->boolean('include_archived')) {
            $query->where('is_active', true);
        }
        $rows = $query->orderBy('name')->get();
        $items = $rows->map(fn ($row) => $row->toArray())->all();
        if ($kind === 'unit') {
            foreach (IndustryCatalog::SYSTEM_UNITS as $code => $system) {
                $items[] = [
                    'id' => 'system:'.$code,
                    'company_id' => $company->id,
                    'kind' => 'unit',
                    'code' => $code,
                    'name' => $system['name'],
                    'precision' => $system['precision'],
                    'is_active' => true,
                    'is_system' => true,
                    'version' => 1,
                ];
            }
        }

        return response()->json(['data' => $items]);
    }

    public function store(Request $request, Company $company, string $kind, CompanyAccess $access): JsonResponse
    {
        $access->canWrite($request, $company);
        $kind = $this->kind($kind);
        $data = $request->validate([
            'code' => ['required', 'string', 'min:1', 'max:80', 'regex:/\A[A-Za-z0-9_-]+\z/D'],
            'name' => ['required', 'string', 'min:2', 'max:200'],
            'parent_id' => [$kind === 'model' ? 'required' : 'prohibited', 'nullable', 'uuid'],
            'precision' => [$kind === 'unit' ? 'required' : 'prohibited', 'nullable', 'integer', Rule::in([0, 3])],
        ]);
        $code = strtolower(trim($data['code']));
        if ($kind === 'unit' && (strlen($code) > 16 || isset(IndustryCatalog::SYSTEM_UNITS[$code]))) {
            throw ValidationException::withMessages(['code' => ['Unit code is reserved or too long']]);
        }
        $parent = $this->parent($company, $kind, $data['parent_id'] ?? null);
        $normalized = IndustryCatalog::normalized($data['name']);
        $this->ensureUnique($company, $kind, $code, $normalized, $parent?->id);

        $item = $company->industryCatalogItems()->create([
            'id' => (string) Str::uuid(),
            'kind' => $kind,
            'code' => $code,
            'name' => trim($data['name']),
            'normalized_name' => $normalized,
            'parent_id' => $parent?->id,
            'parent_key' => $parent?->id ?? 'root',
            'precision' => $kind === 'unit' ? (int) $data['precision'] : null,
            'source' => 'manual',
            'is_active' => true,
            'version' => 1,
        ]);

        return response()->json(['data' => $item], 201);
    }

    public function update(Request $request, Company $company, string $kind, string $item, CompanyAccess $access): JsonResponse
    {
        $access->canWrite($request, $company);
        $kind = $this->kind($kind);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'min:2', 'max:200'],
        ]);
        $row = DB::transaction(function () use ($company, $kind, $item, $data) {
            $row = $company->industryCatalogItems()->where('kind', $kind)->whereKey($item)
                ->lockForUpdate()->firstOrFail();
            abort_unless($row->version === $data['version'], 409, 'Catalog entry changed; reload');
            abort_unless($row->is_active, 409, 'Archived catalog entry');
            abort_unless($row->source === 'manual', 403);
            $normalized = IndustryCatalog::normalized($data['name']);
            $this->ensureUnique($company, $kind, $row->code, $normalized, $row->parent_id, $row->id);
            $row->name = trim($data['name']);
            $row->normalized_name = $normalized;
            if ($row->isDirty()) {
                $row->version++;
                $row->save();
            }
            return $row->refresh();
        }, 3);

        return response()->json(['data' => $row]);
    }

    public function archive(Request $request, Company $company, string $kind, string $item, CompanyAccess $access): JsonResponse
    {
        $access->canWrite($request, $company);
        $kind = $this->kind($kind);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $row = DB::transaction(function () use ($company, $kind, $item, $data) {
            $row = $company->industryCatalogItems()->where('kind', $kind)->whereKey($item)
                ->lockForUpdate()->firstOrFail();
            abort_unless($row->version === $data['version'], 409, 'Catalog entry changed; reload');
            abort_unless($row->is_active, 409, 'Already archived');
            abort_unless($row->source === 'manual', 403);
            if ($kind === 'unit') {
                abort_if($company->stockItems()->where('unit', $row->code)->exists(), 409,
                    'Unit is in use by stock items');
            }
            if ($kind === 'manufacturer') {
                abort_if($company->industryCatalogItems()->where('kind', 'model')
                    ->where('parent_id', $row->id)->where('is_active', true)->exists(), 409,
                    'Archive manufacturer models first');
            }
            $row->is_active = false;
            $row->version++;
            $row->save();
            return $row->refresh();
        }, 3);

        return response()->json(['data' => $row]);
    }

    private function parent(Company $company, string $kind, ?string $id): ?object
    {
        if ($kind !== 'model') {
            return null;
        }
        $parent = $company->industryCatalogItems()->where('kind', 'manufacturer')
            ->where('is_active', true)->whereKey($id)->first();
        if (! $parent) {
            throw ValidationException::withMessages(['parent_id' => ['Choose an active manufacturer from this company']]);
        }
        return $parent;
    }

    private function ensureUnique(
        Company $company, string $kind, string $code, string $normalized, ?string $parentId,
        ?string $exceptId = null
    ): void {
        $query = $company->industryCatalogItems()->where('kind', $kind);
        if ($exceptId !== null) $query->where('id', '!=', $exceptId);
        if ((clone $query)->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => ['Code already exists']]);
        }
        if ((clone $query)->where('parent_key', $parentId ?? 'root')
            ->where('normalized_name', $normalized)->exists()) {
            throw ValidationException::withMessages(['name' => ['Name already exists']]);
        }
    }
}
