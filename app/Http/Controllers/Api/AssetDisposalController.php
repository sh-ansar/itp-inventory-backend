<?php

namespace App\Http\Controllers\Api;

use App\Domain\Inventory\AssetAuditWriter;
use App\Domain\Inventory\AssetDisposalWorkflow;
use App\Domain\Inventory\CompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\AssetDisposalCase;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class AssetDisposalController extends Controller
{
    private function record(Company $company, string $assetId): AssetDisposalCase
    {
        $company->assets()->withTrashed()->whereKey($assetId)->firstOrFail();

        return $company->assetDisposalCases()->with(['asset', 'documents'])
            ->where('asset_id', $assetId)->firstOrFail();
    }

    private function present(AssetDisposalCase $case): array
    {
        $case->loadMissing(['asset', 'documents']);
        $asset = $case->asset;

        return [
            'id' => $case->id,
            'asset_id' => $case->asset_id,
            'status' => $case->status,
            'reason' => $case->reason,
            'order_number' => $case->order_number,
            'order_date' => $case->order_date?->format('Y-m-d'),
            'disposal_act_number' => $case->disposal_act_number,
            'disposal_date' => $case->disposal_date?->format('Y-m-d'),
            'disposal_method' => $case->disposal_method,
            'version' => $case->version,
            'created_at' => $case->created_at?->toIso8601String(),
            'updated_at' => $case->updated_at?->toIso8601String(),
            'asset' => [
                'id' => $asset?->id,
                'inventory_number' => $asset?->inventory_number,
                'name' => $asset?->name,
                'status' => $asset?->status,
                'version' => $asset?->version,
            ],
            'documents' => $case->documents->sortBy('created_at')->values()->map(fn ($doc) => [
                'id' => $doc->id,
                'document_type' => $doc->document_type,
                'original_name' => $doc->original_name,
                'bytes' => $doc->bytes,
                'sha256' => $doc->sha256,
                'uploaded_by_name' => $doc->uploaded_by_name,
                'created_at' => $doc->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    public function index(Request $request, Company $company, CompanyAccess $access): JsonResponse
    {
        $access->member($request, $company);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['draft', 'submitted', 'approved', 'disposed'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = $company->assetDisposalCases()->with(['asset', 'documents']);
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $term = mb_strtolower(trim($filters['search']));
            $term = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
            $query->whereHas('asset', function ($q) use ($term) {
                $q->whereRaw("LOWER(inventory_number) LIKE ? ESCAPE '!'", ['%'.$term.'%'])
                    ->orWhereRaw("LOWER(name) LIKE ? ESCAPE '!'", ['%'.$term.'%']);
            });
        }
        $page = $query->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($case) => $this->present($case))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, Company $company, string $asset, CompanyAccess $access): JsonResponse
    {
        $access->member($request, $company);

        return response()->json(['data' => $this->present($this->record($company, $asset))]);
    }

    public function create(
        Request $request, Company $company, string $asset,
        CompanyAccess $access, AssetDisposalWorkflow $workflow
    ): JsonResponse {
        $access->canWrite($request, $company);
        $data = $request->validate([
            'asset_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'order_number' => ['nullable', 'string', 'max:120'],
            'order_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $case = $workflow->create($company, $asset, $request->user(), $data);

        return response()->json(['data' => $this->present($case)], 201);
    }

    public function update(
        Request $request, Company $company, string $asset,
        CompanyAccess $access, AssetDisposalWorkflow $workflow
    ): JsonResponse {
        $access->canWrite($request, $company);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'order_number' => ['nullable', 'string', 'max:120'],
            'order_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $case = $workflow->update($company, $asset, $request->user(), $data);

        return response()->json(['data' => $this->present($case)]);
    }

    public function submit(
        Request $request, Company $company, string $asset,
        CompanyAccess $access, AssetDisposalWorkflow $workflow
    ): JsonResponse {
        $access->canWrite($request, $company);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $case = $workflow->submit($company, $asset, $request->user(), $data['version']);

        return response()->json(['data' => $this->present($case)]);
    }

    public function returnForCorrection(
        Request $request, Company $company, string $asset,
        CompanyAccess $access, AssetDisposalWorkflow $workflow
    ): JsonResponse {
        $access->canArchive($request, $company);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $case = $workflow->returnForCorrection(
            $company, $asset, $request->user(), $data['version'], $data['reason']
        );

        return response()->json(['data' => $this->present($case)]);
    }

    public function approve(
        Request $request, Company $company, string $asset,
        CompanyAccess $access, AssetDisposalWorkflow $workflow, AssetAuditWriter $audit
    ): JsonResponse {
        $access->canArchive($request, $company);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'asset_version' => ['required', 'integer', 'min:1'],
        ]);
        $case = $workflow->approve($company, $asset, $request->user(), $data, $audit);

        return response()->json(['data' => $this->present($case)]);
    }

    public function dispose(
        Request $request, Company $company, string $asset,
        CompanyAccess $access, AssetDisposalWorkflow $workflow, AssetAuditWriter $audit
    ): JsonResponse {
        $access->canArchive($request, $company);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'asset_version' => ['required', 'integer', 'min:1'],
            'disposal_act_number' => ['required', 'string', 'min:2', 'max:120'],
            'disposal_date' => ['required', 'date_format:Y-m-d'],
            'disposal_method' => ['required', Rule::in(AssetDisposalWorkflow::METHODS)],
        ]);
        $case = $workflow->dispose($company, $asset, $request->user(), $data, $audit);

        return response()->json(['data' => $this->present($case)]);
    }

    private function processDocument(UploadedFile $file): array
    {
        $raw = file_get_contents($file->getRealPath());
        $mime = $file->getMimeType();
        if ($mime === 'application/pdf') {
            if (! str_starts_with($raw, '%PDF-')) {
                throw ValidationException::withMessages(['file' => ['Invalid PDF signature']]);
            }

            return [$raw, 'application/pdf', '.pdf'];
        }
        // Never serve user-provided image bytes directly; re-encode after validation.
        $imageInfo = @getimagesizefromstring($raw);
        if (! $imageInfo || $imageInfo[0] > 4000 || $imageInfo[1] > 4000
            || $imageInfo[0] < 1 || $imageInfo[1] < 1) {
            throw ValidationException::withMessages(['file' => ['Image dimensions must be 1–4000 pixels']]);
        }
        $image = @imagecreatefromstring($raw);
        if (! $image) {
            throw ValidationException::withMessages(['file' => ['Image could not be decoded']]);
        }
        ob_start();
        imagepng($image, null, 8);
        $processed = (string) ob_get_clean();
        imagedestroy($image);
        if (strlen($processed) > 5_242_880) {
            throw ValidationException::withMessages(['file' => ['Processed image is too large']]);
        }

        return [$processed, 'image/png', '.png'];
    }

    public function uploadDocument(
        Request $request, Company $company, string $asset,
        CompanyAccess $access, AssetDisposalWorkflow $workflow
    ): JsonResponse {
        $access->canWrite($request, $company);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'document_type' => ['required', Rule::in(['write_off_order', 'disposal_act', 'supporting'])],
            'file' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:5120'],
        ]);
        [$bytes, $mime, $extension] = $this->processDocument($data['file']);
        $path = 'inventory-disposals/'.$company->id.'/'.Str::uuid().$extension;
        abort_unless(Storage::disk('local')->put($path, $bytes), 507,
            'Could not persist the attachment in private storage');
        $original = preg_replace('/[\\x00-\\x1F\\x7F]+/u', '', basename(str_replace('\\', '/', $data['file']->getClientOriginalName())));
        $original = mb_substr($original ?: 'document'.$extension, 0, 180);
        try {
            $case = DB::transaction(function () use (
                $request, $company, $asset, $data, $workflow, $path, $bytes, $mime, $original
            ) {
                [$lockedAsset, $case] = $workflow->lockCase($company, $asset, $data['version']);
                abort_unless(in_array($case->status, ['draft', 'submitted', 'approved'], true),
                    409, 'Completed cases cannot receive documents');
                abort_unless(
                    ($data['document_type'] === 'disposal_act' && $case->status === 'approved')
                    || ($data['document_type'] === 'write_off_order' && $case->status === 'draft')
                    || ($data['document_type'] === 'supporting'),
                    409, 'This document type is not accepted at the current stage'
                );
                abort_unless($lockedAsset->status === ($case->status === 'approved' ? 'written_off' : 'active'),
                    409, 'Asset and case statuses do not match');

                $document = $case->documents()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'document_type' => $data['document_type'],
                    'disk_path' => $path,
                    'original_name' => $original,
                    'mime_type' => $mime,
                    'bytes' => strlen($bytes),
                    'sha256' => hash('sha256', $bytes),
                    'uploaded_by' => $request->user()->getAuthIdentifier(),
                    'uploaded_by_name' => $request->user()->name,
                ]);
                $workflow->event($case, $request->user(), 'document_uploaded', null, [
                    'document_id' => $document->id,
                    'document_type' => $document->document_type,
                    'original_name' => $document->original_name,
                    'sha256' => $document->sha256,
                ]);

                return $case;
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return response()->json(['data' => $this->present($case)], 201);
    }

    public function downloadDocument(
        Request $request, Company $company, string $asset, string $document,
        CompanyAccess $access
    ): BinaryFileResponse {
        $access->member($request, $company);
        $case = $this->record($company, $asset);
        $file = $case->documents()->whereKey($document)->firstOrFail();
        abort_unless(str_starts_with($file->disk_path,
            'inventory-disposals/'.$company->id.'/'), 404);
        abort_unless(Storage::disk('local')->exists($file->disk_path), 404);

        return response()->download(
            Storage::disk('local')->path($file->disk_path),
            $file->original_name,
            [
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => 'attachment',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
                'X-Frame-Options' => 'DENY',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ]
        );
    }

    public function events(
        Request $request, Company $company, string $asset, CompanyAccess $access
    ): JsonResponse {
        $access->member($request, $company);
        $case = $this->record($company, $asset);
        $events = $case->events()->orderByDesc('version')->limit(100)->get()->map(fn ($event) => [
            'id' => $event->id,
            'event_type' => $event->event_type,
            'version' => $event->version,
            'actor_name' => $event->actor_name,
            'reason' => $event->reason,
            'before' => $event->before,
            'after' => $event->after,
            'created_at' => $event->created_at?->toIso8601String(),
        ]);

        return response()->json(['data' => $events]);
    }
}
