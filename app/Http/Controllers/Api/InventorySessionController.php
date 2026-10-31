<?php

namespace App\Http\Controllers\Api;

use App\Domain\Inventory\CompanyAccess;
use App\Domain\Inventory\InventorySessionService;
use App\Http\Controllers\Controller;
use App\Http\Resources\InventorySessionEventResource;
use App\Http\Resources\InventorySessionItemResource;
use App\Http\Resources\InventorySessionResource;
use App\Models\Company;
use App\Models\InventorySession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class InventorySessionController extends Controller
{
    private function scope(Company $company, string $id): InventorySession
    {
        return $company->inventorySessions()->whereKey($id)->firstOrFail();
    }

    public function index(
        Request $request, Company $company, CompanyAccess $access
    ): AnonymousResourceCollection {
        $access->member($request, $company);
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['in_progress', 'completed'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = $company->inventorySessions();
        if (! empty($data['search'])) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'],
                mb_strtolower(trim($data['search']))).'%';
            $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]);
        }
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        return InventorySessionResource::collection(
            $query->orderByDesc('created_at')->orderByDesc('id')
                ->paginate($data['per_page'] ?? 25)->appends($request->query())
        );
    }

    public function store(
        Request $request, Company $company, CompanyAccess $access,
        InventorySessionService $service
    ): JsonResponse {
        $access->canWrite($request, $company);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:200'],
            'scope_location_id' => [
                'nullable', 'uuid',
                Rule::exists('asset_locations', 'id')
                    ->where('company_id', $company->id)
                    ->where('kind', 'physical'),
            ],
            'facility_node_id' => ['nullable', 'uuid', Rule::exists('facility_nodes', 'id')->where('company_id', $company->id)],
            'asset_ids' => ['sometimes', 'array', 'min:1', 'max:1000'],
            'asset_ids.*' => ['required', 'uuid', 'distinct'],
        ]);

        $session = $service->start($company, $request->user(), $data);

        return (new InventorySessionResource($session))->response()->setStatusCode(201);
    }

    public function show(
        Request $request, Company $company, string $session, CompanyAccess $access
    ): InventorySessionResource {
        $access->member($request, $company);

        return new InventorySessionResource($this->scope($company, $session));
    }

    public function items(
        Request $request, Company $company, string $session, CompanyAccess $access
    ): AnonymousResourceCollection {
        $access->member($request, $company);
        $model = $this->scope($company, $session);
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'counted' => ['nullable', Rule::in(['0', '1'])],
            'discrepancy' => ['nullable', Rule::in(['0', '1'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = $model->items();
        if (! empty($data['search'])) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'],
                mb_strtolower(trim($data['search']))).'%';
            $query->where(fn ($q) => $q
                ->whereRaw("LOWER(inventory_number) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]));
        }
        if (isset($data['counted'])) {
            $query->where('is_counted', $data['counted'] === '1');
        }
        // Facility-based and legacy flat-location stocktakes are separate modes.
        // Use the same comparison as InventorySessionItemResource and the audit service.
        $actual = 'CASE WHEN expected_facility_node_id IS NOT NULL THEN '.
            "COALESCE(CAST(actual_facility_node_id AS TEXT),'') ".
            "ELSE COALESCE(CAST(actual_location_id AS TEXT),'') END";
        $expected = 'CASE WHEN expected_facility_node_id IS NOT NULL THEN '.
            "COALESCE(CAST(expected_facility_node_id AS TEXT),'') ".
            "ELSE COALESCE(CAST(expected_location_id AS TEXT),'') END";
        if (($data['discrepancy'] ?? '') === '1') {
            $query->where('is_counted', true)->whereRaw("($actual) <> ($expected)");
        }
        if (($data['discrepancy'] ?? '') === '0') {
            $query->where(fn ($q) => $q->where('is_counted', false)
                ->orWhereRaw("($actual) = ($expected)"));
        }

        return InventorySessionItemResource::collection(
            $query->orderBy('inventory_number')->orderBy('id')
                ->paginate($data['per_page'] ?? 25)->appends($request->query())
        );
    }

    public function count(
        Request $request, Company $company, string $session, string $item,
        CompanyAccess $access, InventorySessionService $service
    ): JsonResponse {
        $access->canWrite($request, $company);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'actual_location_id' => [
                'nullable', 'required_without:actual_facility_node_id', 'uuid',
                Rule::exists('asset_locations', 'id')
                    ->where('company_id', $company->id)->where('kind', 'physical'),
            ],
            'actual_facility_node_id' => ['nullable', 'required_without:actual_location_id', 'uuid',
                Rule::exists('facility_nodes', 'id')->where('company_id', $company->id)],
            'note' => ['nullable', 'string', 'max:1000'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        [$model, $counted] = $service->countItem(
            $company, $session, $item, $request->user(), $data
        );

        return response()->json([
            'data' => [
                'session' => (new InventorySessionResource($model))->resolve($request),
                'item' => (new InventorySessionItemResource($counted))->resolve($request),
            ],
        ]);
    }

    public function scan(
        Request $request, Company $company, string $session,
        CompanyAccess $access, InventorySessionService $service
    ): JsonResponse {
        $access->canWrite($request, $company);
        $data = $request->validate([
            'public_id' => ['required', 'uuid'],
            'actual_location_id' => [
                'nullable', 'required_without:actual_facility_node_id', 'uuid',
                Rule::exists('asset_locations', 'id')
                    ->where('company_id', $company->id)->where('kind', 'physical'),
            ],
            'actual_facility_node_id' => ['nullable', 'required_without:actual_location_id', 'uuid',
                Rule::exists('facility_nodes', 'id')->where('company_id', $company->id)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $model = $this->scope($company, $session);
        $asset = $company->assets()->where('public_id', $data['public_id'])
            ->where('status', 'active')->firstOrFail();
        $item = $model->items()->where('asset_id', $asset->id)->firstOrFail();
        // No implicit recount on a repeated QR scan: 409 and explicit review.
        [$updated, $counted] = $service->countItem($company, $session, $item->id,
            $request->user(), [
                'version' => $item->version,
                'actual_location_id' => $data['actual_location_id'] ?? null,
                'actual_facility_node_id' => $data['actual_facility_node_id'] ?? null,
                'note' => $data['note'] ?? null,
            ], false);

        return response()->json([
            'data' => [
                'session' => (new InventorySessionResource($updated))->resolve($request),
                'item' => (new InventorySessionItemResource($counted))->resolve($request),
            ],
        ]);
    }

    public function complete(
        Request $request, Company $company, string $session,
        CompanyAccess $access, InventorySessionService $service
    ): InventorySessionResource {
        $access->canWrite($request, $company);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
        ]);

        return new InventorySessionResource(
            $service->finish($company, $session, $request->user(), $data['version'])
        );
    }

    public function reopen(
        Request $request, Company $company, string $session,
        CompanyAccess $access, InventorySessionService $service
    ): InventorySessionResource {
        $access->canArchive($request, $company);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        return new InventorySessionResource(
            $service->reopen($company, $session, $request->user(),
                $data['version'], trim($data['reason']))
        );
    }

    public function events(
        Request $request, Company $company, string $session, CompanyAccess $access
    ): AnonymousResourceCollection {
        $access->member($request, $company);
        $model = $this->scope($company, $session);

        return InventorySessionEventResource::collection(
            $model->events()->orderByDesc('version')->paginate(50)
        );
    }

    public function export(
        Request $request, Company $company, string $session, CompanyAccess $access
    ): StreamedResponse {
        $access->member($request, $company);
        $model = $this->scope($company, $session);
        $filename = 'inventory-'.$model->id.'.csv';

        return response()->streamDownload(function () use ($model): void {
            $out = fopen('php://output', 'wb');
            if (! $out) {
                throw new \RuntimeException('Unable to stream inventory report');
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Инвентарный №', 'Наименование', 'Ожидаемое место',
                'Фактическое место', 'Объект по схеме', 'Объект проверки',
                'Проверено', 'Расхождение', 'Проверил',
                'Дата проверки', 'Примечание',
            ], ';', '"', '');
            foreach ($model->items()->orderBy('inventory_number')->cursor() as $row) {
                $cells = [
                    $row->inventory_number,
                    $row->name,
                    $row->expected_location_name,
                    $row->actual_location_name,
                    $row->expected_facility_node_name,
                    $row->actual_facility_node_name,
                    $row->is_counted ? 'Да' : 'Нет',
                    $row->is_counted && ($row->expected_facility_node_id !== null
                        ? $row->actual_facility_node_id !== $row->expected_facility_node_id
                        : $row->actual_location_id !== $row->expected_location_id) ? 'Да' : 'Нет',
                    $row->counted_by_name,
                    $row->counted_at?->toIso8601String(),
                    $row->note,
                ];
                // Neutralize spreadsheet formula injection from imported asset fields.
                $safe = array_map(static function ($value): string {
                    $str = (string) ($value ?? '');

                    return preg_match('/\A[\s]*[=+\-@]/u', $str) ? "'".$str : $str;
                }, $cells);
                fputcsv($out, $safe, ';', '"', '');
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
