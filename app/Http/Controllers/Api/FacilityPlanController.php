<?php

namespace App\Http\Controllers\Api;

use App\Domain\Inventory\CompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\FacilityNode;
use App\Models\FacilityPlan;
use App\Models\FacilityPlanPlacement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class FacilityPlanController extends Controller
{
    private const MAX_ELEMENTS = 500;

    private function node(Company $company, string $id, bool $lock = false): FacilityNode
    {
        $query = $company->facilityNodes()->whereKey($id);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    private function plan(Company $company, FacilityNode $node): FacilityPlan
    {
        // Lock the facility node first in every mutator. Even a brand-new plan
        // is then serialized, so two concurrent first writes cannot race.
        return $company->facilityPlans()->firstOrCreate(
            ['facility_node_id' => $node->id],
            [
                'id' => (string) Str::uuid(),
                'canvas_width' => 1200,
                'canvas_height' => 800,
                'version' => 0,
            ]
        );
    }

    private function current(Company $company, FacilityNode $node): ?FacilityPlan
    {
        return $company->facilityPlans()->where('facility_node_id', $node->id)->first();
    }

    private function version(FacilityPlan $plan, int $version): void
    {
        abort_unless($plan->version === $version, 409, 'Plan changed in another session; refresh before saving');
    }

    private function rectangle(array $data, FacilityPlan $plan): array
    {
        $rect = [
            'x' => (int) $data['x'],
            'y' => (int) $data['y'],
            'width' => (int) $data['width'],
            'height' => (int) $data['height'],
        ];
        if ($plan->canvas_width < $rect['x'] + $rect['width'] ||
            $plan->canvas_height < $rect['y'] + $rect['height']) {
            throw ValidationException::withMessages([
                'x' => ['Object must remain entirely inside the current plan canvas'],
            ]);
        }

        return $rect;
    }

    private function geometryRules(): array
    {
        return [
            'x' => ['required', 'integer', 'between:0,2400'],
            'y' => ['required', 'integer', 'between:0,1800'],
            'width' => ['required', 'integer', 'between:32,1000'],
            'height' => ['required', 'integer', 'between:32,900'],
            'expected_version' => ['required', 'integer', 'min:0'],
        ];
    }

    private function event(FacilityPlan $plan, Request $request, string $type, ?array $before, ?array $after): void
    {
        $plan->version++;
        $plan->save();
        $plan->events()->create([
            'id' => (string) Str::uuid(),
            'company_id' => $plan->company_id,
            'event_type' => $type,
            'version' => $plan->version,
            'actor_id' => $request->user()->id,
            'actor_name' => $request->user()->name,
            'before' => $before,
            'after' => $after,
        ]);
    }

    private function placementSnapshot(FacilityPlanPlacement $placement): array
    {
        return $placement->only(['id', 'asset_id', 'child_node_id', 'x', 'y', 'width', 'height']);
    }

    private function imageHref(Company $company, string $node): string
    {
        return '/inventory-api/api/v1/companies/'.$company->id.'/facility-nodes/'.$node.'/plan/background';
    }

    public function show(Request $request, Company $company, string $node, CompanyAccess $access): JsonResponse
    {
        $access->member($request, $company);
        $facility = $this->node($company, $node);
        $plan = $this->current($company, $facility);
        $children = $facility->children()->orderBy('sort_order')->orderBy('name')
            ->limit(self::MAX_ELEMENTS + 1)->get();
        $assets = $facility->assets()->where('status', 'active')
            ->with(['category:id,company_id,icon_key,icon_path', 'responsiblePerson:id,name'])
            ->orderBy('inventory_number')->limit(self::MAX_ELEMENTS + 1)->get();
        abort_if($children->count() + $assets->count() > self::MAX_ELEMENTS, 413,
            'Too many items for one floor plan; split into separate rooms or zones');

        $byAsset = [];
        $byChild = [];
        if ($plan) {
            foreach ($plan->placements()->get() as $placement) {
                if ($placement->asset_id) {
                    $byAsset[$placement->asset_id] = $placement;
                }
                if ($placement->child_node_id) {
                    $byChild[$placement->child_node_id] = $placement;
                }
            }
        }

        $elements = [];
        foreach ($children as $child) {
            $p = $byChild[$child->id] ?? null;
            $elements[] = [
                'kind' => 'node',
                'id' => $child->id,
                'placement_id' => $p?->id,
                'name' => $child->name,
                'code' => $child->code,
                'node_type' => $child->node_type,
                'icon_key' => null,
                'icon_url' => null,
                'responsible_name' => null,
                'x' => $p?->x,
                'y' => $p?->y,
                'width' => $p?->width,
                'height' => $p?->height,
            ];
        }
        foreach ($assets as $asset) {
            $p = $byAsset[$asset->id] ?? null;
            $elements[] = [
                'kind' => 'asset',
                'id' => $asset->id,
                'placement_id' => $p?->id,
                'name' => $asset->name,
                'code' => $asset->inventory_number,
                'node_type' => null,
                'icon_key' => $asset->category?->icon_key ?? 'box',
                'icon_url' => $asset->category?->icon_path
                    ? '/inventory-api/api/v1/companies/'.$company->id.'/categories/'.$asset->category_id.'/icon'
                    : null,
                'responsible_name' => $asset->responsiblePerson?->name,
                'x' => $p?->x,
                'y' => $p?->y,
                'width' => $p?->width,
                'height' => $p?->height,
            ];
        }

        return response()->json(['data' => [
            'node_id' => $facility->id,
            'node_name' => $facility->name,
            'node_type' => $facility->node_type,
            'parent_id' => $facility->parent_id,
            'canvas_width' => $plan?->canvas_width ?? 1200,
            'canvas_height' => $plan?->canvas_height ?? 800,
            'version' => $plan?->version ?? 0,
            'background_url' => $plan?->background_path ? $this->imageHref($company, $node).'?v='.$plan->version : null,
            'elements' => $elements,
        ]]);
    }

    public function resize(Request $request, Company $company, string $node, CompanyAccess $access): JsonResponse
    {
        $access->canWrite($request, $company);
        $data = $request->validate([
            'expected_version' => ['required', 'integer', 'min:0'],
            'canvas_width' => ['required', 'integer', 'between:600,2400'],
            'canvas_height' => ['required', 'integer', 'between:400,1800'],
        ]);
        $result = DB::transaction(function () use ($request, $company, $node, $data) {
            $facility = $this->node($company, $node, true);
            $plan = $this->plan($company, $facility);
            $this->version($plan, $data['expected_version']);
            if ($plan->placements()->whereRaw('(x + width) > ? OR (y + height) > ?', [
                $data['canvas_width'], $data['canvas_height'],
            ])->exists()) {
                throw ValidationException::withMessages([
                    'canvas_width' => ['Move objects inside the new dimensions before reducing plan size'],
                ]);
            }
            $before = $plan->only(['canvas_width', 'canvas_height']);
            $plan->canvas_width = $data['canvas_width'];
            $plan->canvas_height = $data['canvas_height'];
            if ($plan->isDirty()) {
                $this->event($plan, $request, 'canvas_resized', $before,
                    $plan->only(['canvas_width', 'canvas_height']));
            }

            return $plan->refresh();
        }, 3);

        return response()->json(['data' => ['version' => $result->version,
            'canvas_width' => $result->canvas_width, 'canvas_height' => $result->canvas_height]]);
    }

    public function place(Request $request, Company $company, string $node, CompanyAccess $access): JsonResponse
    {
        $access->canWrite($request, $company);
        $data = $request->validate([
            ...$this->geometryRules(),
            'asset_id' => ['nullable', 'uuid'],
            'child_node_id' => ['nullable', 'uuid'],
        ]);
        if (isset($data['asset_id']) === isset($data['child_node_id'])) {
            throw ValidationException::withMessages([
                'asset_id' => ['Choose exactly one asset or directly nested facility'],
            ]);
        }
        $response = DB::transaction(function () use ($request, $company, $node, $data) {
            $facility = $this->node($company, $node, true);
            $plan = $this->plan($company, $facility);
            $this->version($plan, $data['expected_version']);
            $rect = $this->rectangle($data, $plan);
            if (isset($data['asset_id'])) {
                $company->assets()->whereKey($data['asset_id'])
                    ->where('status', 'active')->where('facility_node_id', $node)->firstOrFail();
                if ($plan->placements()->where('asset_id', $data['asset_id'])->exists()) {
                    throw ValidationException::withMessages(['asset_id' => ['Asset already placed on this plan']]);
                }
            } else {
                $company->facilityNodes()->whereKey($data['child_node_id'])
                    ->where('parent_id', $node)->firstOrFail();
                if ($plan->placements()->where('child_node_id', $data['child_node_id'])->exists()) {
                    throw ValidationException::withMessages(['child_node_id' => ['This facility is already placed']]);
                }
            }
            $placement = $plan->placements()->create([
                'id' => (string) Str::uuid(),
                'company_id' => $company->id,
                'asset_id' => $data['asset_id'] ?? null,
                'child_node_id' => $data['child_node_id'] ?? null,
                ...$rect,
            ]);
            $this->event($plan, $request, 'placed', null, $this->placementSnapshot($placement));

            return ['placement' => $this->placementSnapshot($placement), 'version' => $plan->version];
        }, 3);

        return response()->json(['data' => $response], 201);
    }

    public function move(
        Request $request, Company $company, string $node, string $placement, CompanyAccess $access
    ): JsonResponse {
        $access->canWrite($request, $company);
        $data = $request->validate($this->geometryRules());
        $result = DB::transaction(function () use ($request, $company, $node, $placement, $data) {
            $facility = $this->node($company, $node, true);
            $plan = $this->current($company, $facility);
            abort_unless($plan, 404);
            $this->version($plan, $data['expected_version']);
            $model = $plan->placements()->whereKey($placement)->firstOrFail();
            $before = $this->placementSnapshot($model);
            $model->fill($this->rectangle($data, $plan));
            if ($model->isDirty()) {
                $model->save();
                $this->event($plan, $request, 'moved', $before, $this->placementSnapshot($model));
            }

            return ['placement' => $this->placementSnapshot($model), 'version' => $plan->version];
        }, 3);

        return response()->json(['data' => $result]);
    }

    public function remove(
        Request $request, Company $company, string $node, string $placement, CompanyAccess $access
    ): JsonResponse {
        $access->canWrite($request, $company);
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:0']]);
        $result = DB::transaction(function () use ($request, $company, $node, $placement, $data) {
            $facility = $this->node($company, $node, true);
            $plan = $this->current($company, $facility);
            abort_unless($plan, 404);
            $this->version($plan, $data['expected_version']);
            $model = $plan->placements()->whereKey($placement)->firstOrFail();
            $before = $this->placementSnapshot($model);
            $model->delete();
            $this->event($plan, $request, 'removed', $before, null);

            return ['version' => $plan->version];
        }, 3);

        return response()->json(['data' => $result]);
    }

    public function events(Request $request, Company $company, string $node, CompanyAccess $access): JsonResponse
    {
        $access->member($request, $company);
        $facility = $this->node($company, $node);
        $plan = $this->current($company, $facility);

        return response()->json(['data' => $plan
            ? $plan->events()->orderByDesc('version')->limit(100)->get()
                ->map(fn ($e) => $e->only([
                    'id', 'event_type', 'version', 'actor_name', 'before', 'after', 'created_at',
                ]))
            : []]);
    }

    public function background(Request $request, Company $company, string $node, CompanyAccess $access): BinaryFileResponse
    {
        $access->member($request, $company);
        $facility = $this->node($company, $node);
        $path = $this->current($company, $facility)?->background_path;
        abort_unless(is_string($path) && str_starts_with($path,
            'inventory-plans/'.$company->id.'/'), 404);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => 'image/png',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=600',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function uploadBackground(
        Request $request, Company $company, string $node, CompanyAccess $access
    ): JsonResponse {
        $access->canWrite($request, $company);
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:png,webp', 'max:2048'],
            'expected_version' => ['required', 'integer', 'min:0'],
        ]);
        $bytes = file_get_contents($data['file']->getRealPath());
        $size = @getimagesizefromstring($bytes);
        if (! $size || $size[0] < 100 || $size[1] < 100
            || $size[0] > 2500 || $size[1] > 2500) {
            throw ValidationException::withMessages(['file' => ['PNG/WebP plan must be 100–2500 pixels']]);
        }
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            throw ValidationException::withMessages(['file' => ['Image could not be decoded']]);
        }
        $targetW = min($size[0], 1600);
        $targetH = (int) round($size[1] * ($targetW / $size[0]));
        if ($targetH > 1600) {
            $targetW = (int) round($targetW * (1600 / $targetH));
            $targetH = 1600;
        }
        $canvas = imagecreatetruecolor($targetW, $targetH);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $targetW, $targetH, $transparent);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetW, $targetH, $size[0], $size[1]);
        ob_start();
        imagepng($canvas, null, 8);
        $safePng = (string) ob_get_clean();
        imagedestroy($image);
        imagedestroy($canvas);
        if (strlen($safePng) > 5_000_000) {
            throw ValidationException::withMessages(['file' => ['Processed plan image is too large']]);
        }
        $path = 'inventory-plans/'.$company->id.'/'.Str::uuid().'.png';
        Storage::disk('local')->put($path, $safePng);
        $previous = null;
        try {
            $version = DB::transaction(function () use ($request, $company, $node, $data, $path, &$previous) {
                $facility = $this->node($company, $node, true);
                $plan = $this->plan($company, $facility);
                $this->version($plan, $data['expected_version']);
                $previous = $plan->background_path;
                $plan->background_path = $path;
                $this->event($plan, $request, 'background_uploaded',
                    ['has_background' => (bool) $previous], ['has_background' => true]);

                return $plan->version;
            }, 3);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        if ($previous) {
            Storage::disk('local')->delete($previous);
        }

        return response()->json(['data' => [
            'version' => $version,
            'background_url' => $this->imageHref($company, $node).'?v='.$version,
        ]]);
    }

    public function clearBackground(
        Request $request, Company $company, string $node, CompanyAccess $access
    ): JsonResponse {
        $access->canWrite($request, $company);
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:0']]);
        $previous = null;
        $version = DB::transaction(function () use ($request, $company, $node, $data, &$previous) {
            $facility = $this->node($company, $node, true);
            $plan = $this->current($company, $facility);
            abort_unless($plan, 404);
            $this->version($plan, $data['expected_version']);
            $previous = $plan->background_path;
            if ($previous) {
                $plan->background_path = null;
                $this->event($plan, $request, 'background_cleared', ['has_background' => true],
                    ['has_background' => false]);
            }

            return $plan->version;
        }, 3);
        if ($previous) {
            Storage::disk('local')->delete($previous);
        }

        return response()->json(['data' => ['version' => $version, 'background_url' => null]]);
    }
}
