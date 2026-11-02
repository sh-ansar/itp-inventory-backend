<?php

namespace App\Console\Commands;

use App\Domain\Inventory\AssetAuditWriter;
use App\Domain\Inventory\AssetDisposalWorkflow;
use App\Domain\Inventory\EquipmentTypes;
use App\Domain\Inventory\InventorySessionService;
use App\Domain\Inventory\WarehouseLedger;
use App\Models\AssetDisposalCase;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class EnrichShowcaseInventory extends Command
{
    protected $signature = 'inventory:demo:enrich {--allow-production : Explicitly enable enriching the isolated demo company} {--dry-run : Inspect without writing}';
    protected $description = 'Add base equipment types, an original icon and complete sample workflows ONLY to the synthetic showcase';

    private function company(): Company
    {
        $company = Company::query()->findOrFail(SeedShowcaseInventory::COMPANY_ID);
        if ($company->name !== SeedShowcaseInventory::COMPANY_NAME
            || $company->assets()->count() !== 120
            || ! $company->assetCategories()->where('code', 'D-IT')->exists()
            || $company->inventorySessions()->count() !== 3) {
            throw new RuntimeException('Showcase marker or dataset is inconsistent; refusing mutation');
        }

        return $company;
    }

    private function administrator(Company $company): User
    {
        $membership = CompanyMembership::query()
            ->where('company_id', $company->id)
            ->where('role_code', 'company_admin')->sole();
        $actor = User::query()->findOrFail($membership->user_id);
        if (! $actor->is_active) {
            throw new RuntimeException('Demo administrator is inactive');
        }

        return $actor;
    }

    private function syntheticPdf(string $type): string
    {
        $message = "DEMO - ".($type === 'disposal_act' ? 'Disposal Act' : 'Write-off Order');
        $stream = "BT /F1 16 Tf 55 750 Td ($message) Tj ET\n".
            "BT /F1 11 Tf 55 720 Td (Synthetic sample only - NOT a signed or official document.) Tj ET\n";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($stream)." >>\nstream\n".$stream."endstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach ($offsets as $offset) $pdf .= sprintf("%010d 00000 n \n", $offset);

        return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    private function syntheticIcon(): string
    {
        $image = imagecreatetruecolor(128, 128);
        if (! $image) throw new RuntimeException('GD cannot create sample icon');
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefilledrectangle($image, 0, 0, 127, 127, $transparent);
        imagealphablending($image, true);
        $dark = imagecolorallocate($image, 9, 48, 68);
        $cyan = imagecolorallocate($image, 6, 151, 224);
        $white = imagecolorallocate($image, 252, 254, 255);
        imagefilledellipse($image, 64, 64, 104, 104, $dark);
        imageellipse($image, 64, 64, 101, 101, $cyan);
        imagesetthickness($image, 5);
        imageline($image, 38, 78, 64, 50, $cyan);
        imageline($image, 64, 50, 88, 70, $cyan);
        imagestring($image, 3, 46, 88, 'DEMO', $white);
        ob_start();
        imagepng($image, null, 7);
        $raw = (string) ob_get_clean();
        imagedestroy($image);
        if (! str_starts_with($raw, "\x89PNG\r\n\x1a\n")) {
            throw new RuntimeException('Generated icon is not a valid PNG');
        }

        return $raw;
    }

    private function applyIcon(Company $company): void
    {
        $category = $company->assetCategories()->where('code', 'D-TL')->firstOrFail();
        if ($category->icon_path) {
            if (! str_starts_with($category->icon_path, 'inventory-icons/'.$company->id.'/')
                || ! Storage::disk('local')->exists($category->icon_path)) {
                throw new RuntimeException('Existing demonstration icon path is inconsistent');
            }
            return;
        }
        $path = 'inventory-icons/'.$company->id.'/'.Str::uuid().'.png';
        if (! Storage::disk('local')->put($path, $this->syntheticIcon())) {
            throw new RuntimeException('Could not save demonstration icon');
        }
        try {
            DB::transaction(function () use ($company, $category, $path): void {
                $locked = $company->assetCategories()->whereKey($category->id)->lockForUpdate()->firstOrFail();
                if ($locked->icon_path) {
                    throw new RuntimeException('Demo category icon changed concurrently');
                }
                $locked->icon_path = $path;
                $locked->version++;
                $locked->save();
            }, 3);
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
    }

    private function attachSample(
        Company $company, User $actor, AssetDisposalCase $case,
        string $type, AssetDisposalWorkflow $workflow
    ): void {
        if ($case->documents()->where('document_type', $type)->exists()) return;
        if (($type === 'write_off_order' && $case->status !== 'draft')
            || ($type === 'disposal_act' && $case->status !== 'approved')) {
            throw new RuntimeException('Unexpected disposal stage for demo attachment');
        }
        $pdf = $this->syntheticPdf($type);
        $path = 'inventory-disposals/'.$company->id.'/'.Str::uuid().'.pdf';
        if (! Storage::disk('local')->put($path, $pdf)) {
            throw new RuntimeException('Could not save private demo attachment');
        }
        try {
            DB::transaction(function () use ($company, $actor, $case, $type, $pdf, $path, $workflow): void {
                $locked = $company->assetDisposalCases()->whereKey($case->id)->lockForUpdate()->firstOrFail();
                $doc = $locked->documents()->create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'document_type' => $type,
                    'disk_path' => $path,
                    'original_name' => 'DEMO-'.($type === 'write_off_order' ? 'WRITE-OFF-ORDER' : 'DISPOSAL-ACT').'.pdf',
                    'mime_type' => 'application/pdf',
                    'bytes' => strlen($pdf),
                    'sha256' => hash('sha256', $pdf),
                    'uploaded_by' => $actor->id,
                    'uploaded_by_name' => $actor->name,
                ]);
                $workflow->event($locked, $actor, 'document_uploaded', null, [
                    'document_id' => $doc->id, 'document_type' => $type,
                    'original_name' => $doc->original_name, 'sha256' => $doc->sha256,
                ]);
            }, 3);
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
    }

    private function advance(
        Company $company, User $actor, string $inventoryNumber, string $target,
        AssetDisposalWorkflow $workflow, AssetAuditWriter $audit
    ): void {
        $asset = $company->assets()->where('inventory_number', $inventoryNumber)->firstOrFail();
        $case = $company->assetDisposalCases()->where('asset_id', $asset->id)->first();

        if (! $case) {
            if ($target !== 'approved') throw new RuntimeException('Missing expected demo disposal case');
            $case = $workflow->create($company, $asset->id, $actor, [
                'asset_version' => $asset->version,
                'reason' => 'ДЕМОНСТРАЦИЯ: учебная заявка на списание из-за устаревания оборудования',
                'order_number' => 'DEMO-SP-107',
                'order_date' => '2026-10-15',
            ]);
        }
        if ($case->status === 'draft' && $target !== 'draft') {
            $this->attachSample($company, $actor, $case, 'write_off_order', $workflow);
            $case = $workflow->submit($company, $asset->id, $actor, $case->fresh()->version);
        }
        if ($case->status === 'submitted' && in_array($target, ['approved', 'disposed'], true)) {
            $case = $workflow->approve($company, $asset->id, $actor, [
                'version' => $case->version,
                'asset_version' => $asset->fresh()->version,
            ], $audit);
        }
        if ($case->status === 'approved' && $target === 'disposed') {
            $this->attachSample($company, $actor, $case, 'disposal_act', $workflow);
            $case = $workflow->dispose($company, $asset->id, $actor, [
                'version' => $case->fresh()->version,
                'asset_version' => $asset->fresh()->version,
                'disposal_act_number' => 'DEMO-ACT-106',
                'disposal_date' => '2026-10-25',
                'disposal_method' => 'recycling',
            ], $audit);
        }
        if ($case->status !== $target) {
            throw new RuntimeException('Unexpected resulting demo disposal status: '.$case->status);
        }
    }

    public function handle(
        EquipmentTypes $types, WarehouseLedger $ledger,
        InventorySessionService $sessions, AssetDisposalWorkflow $workflow,
        AssetAuditWriter $audit
    ): int {
        if (app()->environment('production') && ! $this->option('allow-production')) {
            $this->error('Production requires explicit --allow-production.');
            return self::FAILURE;
        }

        try {
            $company = $this->company();
            $actor = $this->administrator($company);
            if ($this->option('dry-run')) {
                $this->info('DEMO_ENRICH_DRY_RUN=PASS');
                return self::SUCCESS;
            }

            $types->ensurePresets($company);
            $this->applyIcon($company);

            $mainWarehouse = $company->facilityNodes()->where('code', 'D-WH1')
                ->where('node_type', 'warehouse')->firstOrFail();
            foreach ($company->stockItems()->orderBy('code')->limit(4)->get() as $item) {
                if ($company->stockTransactions()->where('stock_item_id', $item->id)
                    ->where('operation', 'issue')->exists()) continue;
                $ledger->record($company, $actor, [
                    'stock_item_id' => $item->id, 'operation' => 'issue',
                    'warehouse_node_id' => $mainWarehouse->id,
                    'quantity' => '2',
                    'reason' => 'Учебная выдача материалов на объект',
                ]);
            }

            $first = $company->inventorySessions()
                ->where('name', 'ДЕМО · Плановая инвентаризация ИТ — закрыта')->firstOrFail();
            if ($first->counted_items < $first->total_items) {
                if ($first->status === 'completed') {
                    $first = $sessions->reopen(
                        $company, $first->id, $actor, $first->version,
                        'Учебное дополнение результатов контрольного обхода'
                    );
                }
                foreach ($first->items()->where('is_counted', false)->get() as $row) {
                    $sessions->countItem($company, $first->id, $row->id, $actor, [
                        'version' => $row->version,
                        'actual_facility_node_id' => $row->expected_facility_node_id,
                        'note' => 'Проверено повторно в демонстрационном контрольном обходе',
                    ]);
                }
                $first = $first->fresh();
            }
            if ($first->status === 'in_progress' && $first->counted_items === $first->total_items) {
                $sessions->finish($company, $first->id, $actor, $first->version);
            }

            $this->advance($company, $actor, 'DEMO-IT-0105', 'submitted', $workflow, $audit);
            $this->advance($company, $actor, 'DEMO-IT-0106', 'disposed', $workflow, $audit);
            $this->advance($company, $actor, 'DEMO-IT-0107', 'approved', $workflow, $audit);

            $statuses = $company->assetDisposalCases()->get()->groupBy('status');
            foreach (['draft','submitted','approved','disposed'] as $status) {
                if (($statuses[$status] ?? collect())->count() !== 1) {
                    throw new RuntimeException('Unexpected case counts for '.$status);
                }
            }
            $this->info('DEMO_ENRICHED=TRUE');
            $this->line(json_encode([
                'company' => $company->id,
                'categories' => $company->assetCategories()->count(),
                'base_categories' => $company->assetCategories()->where('is_base', true)->count(),
                'custom_icons' => $company->assetCategories()->whereNotNull('icon_path')->count(),
                'stock_issues' => $company->stockTransactions()->where('operation','issue')->count(),
                'disposals' => $statuses->map->count()->all(),
            ], JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error('DEMO_ENRICH_FAILED: '.$error->getMessage());
            return self::FAILURE;
        }
    }
}
