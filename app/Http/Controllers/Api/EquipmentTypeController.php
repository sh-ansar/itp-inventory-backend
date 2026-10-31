<?php

namespace App\Http\Controllers\Api;

use App\Domain\Inventory\CompanyAccess;
use App\Domain\Inventory\EquipmentTypes;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class EquipmentTypeController extends Controller
{
    public function presets(
        Request $request, Company $company, CompanyAccess $access, EquipmentTypes $types
    ): JsonResponse {
        $access->member($request, $company);

        return response()->json(['data' => EquipmentTypes::PRESETS, 'icon_keys' => EquipmentTypes::ICONS]);
    }

    public function initialize(
        Request $request, Company $company, CompanyAccess $access, EquipmentTypes $types
    ): JsonResponse {
        $access->canWrite($request, $company);
        $added = $types->ensurePresets($company);

        return response()->json(['added' => count($added)]);
    }

    public function update(
        Request $request, Company $company, string $category,
        CompanyAccess $access
    ): JsonResponse {
        $access->canWrite($request, $company);
        $model = $company->assetCategories()->whereKey($category)->firstOrFail();
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'min:2', 'max:200'],
            'icon_key' => ['required', Rule::in(EquipmentTypes::ICONS)],
        ]);
        $normalized = EquipmentTypes::normalize($data['name']);
        $duplicate = $company->assetCategories()
            ->where('id', '!=', $model->id)
            ->where(fn ($q) => $q->where('normalized_name', $normalized)
                ->orWhereRaw('LOWER(name) = ?', [$normalized]))->exists();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'name' => ['A category with this name already exists in this company'],
            ]);
        }
        $result = DB::transaction(function () use ($company, $model, $data, $normalized) {
            $locked = $company->assetCategories()->whereKey($model->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->version === $data['version'], 409, 'Equipment type changed; reload');
            $locked->fill([
                'name' => trim($data['name']),
                'normalized_name' => $normalized,
                'icon_key' => $data['icon_key'],
            ]);
            if ($locked->isDirty()) {
                $locked->version++;
                $locked->save();
            }

            return $locked->refresh();
        }, 3);

        return response()->json(['data' => $result]);
    }

    public function uploadIcon(
        Request $request, Company $company, string $category, CompanyAccess $access
    ): JsonResponse {
        $access->canWrite($request, $company);
        $model = $company->assetCategories()->whereKey($category)->firstOrFail();
        $data = $request->validate([
            'icon' => ['required', 'file', 'mimes:png,webp', 'max:512'],
            'version' => ['required', 'integer', 'min:1'],
        ]);
        $bytes = file_get_contents($data['icon']->getRealPath());
        $size = @getimagesizefromstring($bytes);
        if (! $size || $size[0] < 16 || $size[1] < 16 || $size[0] > 768 || $size[1] > 768) {
            throw ValidationException::withMessages(['icon' => ['Use a PNG/WebP image of 16–768 pixels']]);
        }
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            throw ValidationException::withMessages(['icon' => ['Cannot decode image']]);
        }
        $w = 128;
        $h = 128;
        $canvas = imagecreatetruecolor($w, $h);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $w, $h, $transparent);
        $scale = min($w / $size[0], $h / $size[1]);
        $dw = (int) round($size[0] * $scale);
        $dh = (int) round($size[1] * $scale);
        imagecopyresampled($canvas, $image, (int) (($w - $dw) / 2), (int) (($h - $dh) / 2),
            0, 0, $dw, $dh, $size[0], $size[1]);
        ob_start();
        imagepng($canvas, null, 9);
        $safePng = (string) ob_get_clean();
        imagedestroy($image);
        imagedestroy($canvas);
        if (strlen($safePng) > 120_000) {
            throw ValidationException::withMessages(['icon' => ['Image conversion too large']]);
        }
        $path = 'inventory-icons/'.$company->id.'/'.Str::uuid().'.png';
        Storage::disk('local')->put($path, $safePng);
        try {
            $result = DB::transaction(function () use ($company, $model, $data, $path) {
                $locked = $company->assetCategories()->whereKey($model->id)->lockForUpdate()->firstOrFail();
                abort_unless($locked->version === $data['version'], 409, 'Equipment type changed; reload');
                $locked->icon_path = $path;
                $locked->version++;
                $locked->save();

                return $locked->refresh();
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return response()->json(['data' => [
            'id' => $result->id, 'version' => $result->version,
            'icon_url' => '/inventory-api/api/v1/companies/'.$company->id
                .'/categories/'.$result->id.'/icon',
        ]]);
    }

    public function icon(
        Request $request, Company $company, string $category, CompanyAccess $access
    ): BinaryFileResponse {
        $access->member($request, $company);
        $model = $company->assetCategories()->whereKey($category)->firstOrFail();
        $path = $model->icon_path;
        abort_unless(is_string($path) && str_starts_with($path, 'inventory-icons/'.$company->id.'/'), 404);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path),[
            'Content-Type' => 'image/png',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
