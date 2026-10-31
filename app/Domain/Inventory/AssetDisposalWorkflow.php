<?php

namespace App\Domain\Inventory;

use App\Models\Asset;
use App\Models\AssetDisposalCase;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AssetDisposalWorkflow
{
    public const METHODS = ['recycling', 'destruction', 'waste_transfer', 'other'];

    private const CASE_FIELDS = [
        'status', 'reason', 'order_number', 'order_date',
        'disposal_act_number', 'disposal_date', 'disposal_method', 'version',
    ];

    private function asset(Company $company, string $assetId): Asset
    {
        // Every workflow transition must lock the asset before locking the case.
        // Asset editing/archiving takes the same asset lock, preventing interleaving.
        return $company->assets()->whereKey($assetId)->lockForUpdate()->firstOrFail();
    }

    private function disposalCase(Company $company, string $assetId): AssetDisposalCase
    {
        return $company->assetDisposalCases()->where('asset_id', $assetId)
            ->lockForUpdate()->firstOrFail();
    }

    public function snapshot(AssetDisposalCase $case): array
    {
        return Arr::only($case->getAttributes(), self::CASE_FIELDS);
    }

    public function event(
        AssetDisposalCase $case, User $actor, string $eventType,
        ?array $before, ?array $after, ?string $reason = null, bool $initial = false
    ): void {
        if (! $initial) {
            $case->version++;
            $case->save();
        }

        $case->events()->create([
            'id' => (string) Str::uuid(),
            'company_id' => $case->company_id,
            'event_type' => $eventType,
            'version' => $case->version,
            'actor_id' => $actor->getAuthIdentifier(),
            'actor_name' => $actor->name,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
        ]);
    }

    private function version(AssetDisposalCase $case, int $version): void
    {
        abort_unless($case->version === $version, 409,
            'Disposal request changed; reload before saving');
    }

    public function lockCase(Company $company, string $assetId, int $version): array
    {
        $asset = $this->asset($company, $assetId);
        $case = $this->disposalCase($company, $assetId);
        $this->version($case, $version);

        return [$asset, $case];
    }

    public function create(Company $company, string $assetId, User $actor, array $data): AssetDisposalCase
    {
        return DB::transaction(function () use ($company, $assetId, $actor, $data) {
            $asset = $this->asset($company, $assetId);
            abort_unless($asset->status === 'active', 409, 'Only active assets can be written off');
            abort_unless($asset->version === $data['asset_version'], 409, 'Asset changed; reload');
            abort_if($company->assetDisposalCases()->where('asset_id', $assetId)->exists(),
                409, 'An immutable disposal case already exists for this asset');

            $case = $company->assetDisposalCases()->create([
                'id' => (string) Str::uuid(),
                'asset_id' => $asset->id,
                'status' => 'draft',
                'reason' => $data['reason'],
                'order_number' => $data['order_number'] ?? null,
                'order_date' => $data['order_date'] ?? null,
                'version' => 1,
            ]);
            $case->refresh();
            $this->event($case, $actor, 'created', null, $this->snapshot($case), null, true);

            return $case;
        }, 3);
    }

    public function update(Company $company, string $assetId, User $actor, array $data): AssetDisposalCase
    {
        return DB::transaction(function () use ($company, $assetId, $actor, $data) {
            [$asset, $case] = $this->lockCase($company, $assetId, $data['version']);
            abort_unless($asset->status === 'active' && $case->status === 'draft',
                409, 'Only draft write-off requests are editable');
            $before = $this->snapshot($case);
            $case->fill(Arr::only($data, ['reason', 'order_number', 'order_date']));
            if ($case->isDirty()) {
                $case->save();
                $this->event($case, $actor, 'updated', $before, $this->snapshot($case));
            }

            return $case;
        }, 3);
    }

    public function submit(Company $company, string $assetId, User $actor, int $version): AssetDisposalCase
    {
        return DB::transaction(function () use ($company, $assetId, $actor, $version) {
            [$asset, $case] = $this->lockCase($company, $assetId, $version);
            abort_unless($asset->status === 'active' && $case->status === 'draft',
                409, 'Only draft write-off requests can be submitted');
            $missing = [];
            if (! $case->order_number || ! $case->order_date) {
                $missing['order_number'] = ['Provide order number and date before submission'];
            }
            if (! $case->documents()->where('document_type', 'write_off_order')->exists()) {
                $missing['documents'] = ['Upload a write-off order before submission'];
            }
            if ($missing) {
                throw ValidationException::withMessages($missing);
            }
            $before = $this->snapshot($case);
            $case->status = 'submitted';
            $case->save();
            $this->event($case, $actor, 'submitted', $before, $this->snapshot($case));

            return $case;
        }, 3);
    }

    public function returnForCorrection(
        Company $company, string $assetId, User $actor, int $version, string $reason
    ): AssetDisposalCase {
        return DB::transaction(function () use ($company, $assetId, $actor, $version, $reason) {
            [$asset, $case] = $this->lockCase($company, $assetId, $version);
            abort_unless($asset->status === 'active' && $case->status === 'submitted',
                409, 'Only submitted requests can be returned for correction');
            $before = $this->snapshot($case);
            $case->status = 'draft';
            $case->save();
            $this->event($case, $actor, 'returned', $before, $this->snapshot($case), $reason);

            return $case;
        }, 3);
    }

    public function approve(
        Company $company, string $assetId, User $actor, array $data, AssetAuditWriter $audit
    ): AssetDisposalCase {
        return DB::transaction(function () use ($company, $assetId, $actor, $data, $audit) {
            [$asset, $case] = $this->lockCase($company, $assetId, $data['version']);
            abort_unless($asset->status === 'active' && $case->status === 'submitted',
                409, 'Only submitted active assets can be written off');
            abort_unless($asset->version === $data['asset_version'], 409, 'Asset changed; reload');
            abort_unless($case->documents()->where('document_type', 'write_off_order')->exists(),
                422, 'Write-off order document is required');
            $before = $this->snapshot($case);
            $case->status = 'approved';
            $case->save();

            $assetBefore = ['status' => $asset->status, 'version' => $asset->version];
            $asset->status = 'written_off';
            $asset->version++;
            $asset->save();
            $audit->record($asset, $actor, 'written_off', $assetBefore,
                ['status' => $asset->status, 'version' => $asset->version,
                    'disposal_case_id' => $case->id, 'order_number' => $case->order_number],
                $case->reason);
            $this->event($case, $actor, 'approved', $before, $this->snapshot($case));

            return $case;
        }, 3);
    }

    public function dispose(
        Company $company, string $assetId, User $actor, array $data, AssetAuditWriter $audit
    ): AssetDisposalCase {
        return DB::transaction(function () use ($company, $assetId, $actor, $data, $audit) {
            [$asset, $case] = $this->lockCase($company, $assetId, $data['version']);
            abort_unless($case->status === 'approved' && $asset->status === 'written_off',
                409, 'Only approved written-off assets can be physically disposed');
            abort_unless($asset->version === $data['asset_version'], 409, 'Asset changed; reload');
            if (! $case->documents()->where('document_type', 'disposal_act')->exists()) {
                throw ValidationException::withMessages([
                    'documents' => ['Upload a disposal act before confirming physical disposal'],
                ]);
            }
            $before = $this->snapshot($case);
            $case->fill([
                'status' => 'disposed',
                'disposal_act_number' => $data['disposal_act_number'],
                'disposal_date' => $data['disposal_date'],
                'disposal_method' => $data['disposal_method'],
            ]);
            $case->save();
            $assetBefore = ['status' => $asset->status, 'version' => $asset->version];
            $asset->status = 'disposed';
            $asset->version++;
            $asset->save();
            $audit->record($asset, $actor, 'disposed', $assetBefore, [
                'status' => $asset->status, 'version' => $asset->version,
                'disposal_case_id' => $case->id, 'disposal_act_number' => $case->disposal_act_number,
            ], 'Disposal act '.$case->disposal_act_number);
            $this->event($case, $actor, 'disposed', $before, $this->snapshot($case));

            return $case;
        }, 3);
    }
}
