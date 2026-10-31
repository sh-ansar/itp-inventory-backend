<?php

namespace App\Http\Controllers\Api;

use App\Domain\Inventory\AssetAuditWriter;
use App\Domain\Inventory\CompanyAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\AssetDisposalResource;
use App\Models\AssetDisposal;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AssetDisposalController extends Controller
{
    public function index(Request $request, Company $company, CompanyAccess $access): AnonymousResourceCollection
    {
        $access->member($request, $company);
        $data = $request->validate([
            'stage' => ['nullable', Rule::in(['write_off', 'disposal'])],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
        ]);
        $query = AssetDisposal::query()->where('company_id', $company->id)
            ->with(['asset:id,company_id,inventory_number,name,status', 'documents']);
        if (! empty($data['stage'])) $query->where('stage', $data['stage']);
        if (! empty($data['search'])) {
            $term = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($data['search'])));
            $pattern = '%'.$term.'%';
            $query->where(function ($q) use ($pattern) {
                $q->whereRaw("LOWER(document_number) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereHas('asset', fn ($sub) => $sub
                        ->whereRaw("LOWER(inventory_number) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]));
            });
        }
        return AssetDisposalResource::collection(
            $query->orderByDesc('created_at')->orderByDesc('id')->paginate($data['per_page'] ?? 25)
        );
    }

    public function store(Request $request, Company $company, string $asset, CompanyAccess $access, AssetAuditWriter $audit): AssetDisposalResource
    {
        // Only an administrator can finalize a legal write-off or disposal.
        $access->canArchive($request, $company);
        $data = $request->validate([
            'stage' => ['required', Rule::in(['write_off', 'disposal'])],
            'version' => ['required', 'integer', 'min:1'],
            'document_number' => ['required', 'string', 'min:1', 'max:100'],
            'document_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'min:5', 'max:3000'],
            'disposal_method' => ['required_if:stage,disposal', 'nullable', 'string', 'min:3', 'max:200'],
            'document' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ]);
        $file = $request->file('document');
        $mime = $file->getMimeType();
        if (! in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
            throw ValidationException::withMessages(['document' => ['Unsupported document content type']]);
        }
        $id = (string) Str::uuid();
        $docId = (string) Str::uuid();
        $path = 'inventory-disposals/'.$company->id.'/'.$id.'/'.$docId;
        // Save outside public web root; compensate after DB failure.
        if (! Storage::disk('local')->putFileAs(dirname($path), $file, basename($path))) {
            abort(500, 'Unable to store the private document');
        }
        try {
            $decision = DB::transaction(function () use ($request, $company, $asset, $data, $audit, $file, $mime, $path, $id, $docId) {
                $model = $company->assets()->whereKey($asset)->lockForUpdate()->firstOrFail();
                abort_unless($model->version === $data['version'], 409, 'Asset was modified; reload before disposal');
                $expected = $data['stage'] === 'write_off' ? 'active' : 'written_off';
                abort_unless($model->status === $expected, 409, 'Invalid asset lifecycle transition');
                // Do not perform disposal while an open stocktake already contains this asset.
                abort_if(DB::table('inventory_session_items as i')
                    ->join('inventory_sessions as s', 'i.session_id', '=', 's.id')
                    ->where('i.company_id', $company->id)->where('i.asset_id', $asset)
                    ->where('s.company_id', $company->id)->where('s.status', 'in_progress')->exists(), 409,
                    'Complete or reconcile active stocktakes before changing asset status');
                $previous = $model->status;
                $model->status = $data['stage'] === 'write_off' ? 'written_off' : 'disposed';
                $model->version++;
                $model->save();

                $decision = AssetDisposal::query()->create([
                    'id' => $id, 'company_id' => $company->id, 'asset_id' => $asset,
                    'stage' => $data['stage'], 'document_number' => trim($data['document_number']),
                    'document_date' => $data['document_date'], 'reason' => trim($data['reason']),
                    'disposal_method' => $data['stage'] === 'disposal' ? trim($data['disposal_method']) : null,
                    'actor_id' => $request->user()->id, 'actor_name' => $request->user()->name,
                    'asset_version' => $model->version,
                ]);
                $decision->documents()->create([
                    'id' => $docId, 'company_id' => $company->id,
                    'original_name' => basename($file->getClientOriginalName()),
                    'storage_path' => $path, 'mime_type' => $mime, 'file_size' => $file->getSize(),
                    'uploaded_by' => $request->user()->id,
                    'uploaded_by_name' => $request->user()->name,
                ]);
                $audit->record($model, $request->user(), $data['stage'], [
                    'status' => $previous, 'version' => $data['version'],
                ], [
                    'status' => $model->status, 'version' => $model->version,
                    'disposal_id' => $id, 'document_number' => $data['document_number'],
                ], $data['reason']);
                return $decision->load(['asset', 'documents']);
            }, 3);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        return new AssetDisposalResource($decision);
    }

    public function download(Request $request, Company $company, string $disposal, string $document, CompanyAccess $access): StreamedResponse
    {
        $access->member($request, $company);
        $record = AssetDisposal::query()->where('company_id', $company->id)->whereKey($disposal)->firstOrFail();
        $file = $record->documents()->where('company_id', $company->id)->whereKey($document)->firstOrFail();
        abort_unless(Storage::disk('local')->exists($file->storage_path), 404);
        return Storage::disk('local')->download($file->storage_path, $file->original_name, [
            'Content-Type' => $file->mime_type, 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
