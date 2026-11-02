<?php

namespace App\Http\Controllers\Api;

use App\Domain\Inventory\AssetAuditWriter;
use App\Domain\Inventory\CompanyAccess;
use App\Domain\Inventory\FacilityHierarchy;
use App\Domain\Inventory\XlsxExport;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class AssetBulkController extends Controller
{
    private function filtered(Company $company, array $filters)
    {
        $query = $company->assets()->with(['category', 'physicalLocation', 'accountingLocation', 'facilityNode', 'responsiblePerson']);
        if (!empty($filters['search'])) {
            $term = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($filters['search'])));
            $like = '%'.$term.'%';
            $query->where(fn ($q) => $q->whereRaw("LOWER(inventory_number) LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("LOWER(serial_number) LIKE ? ESCAPE '!'", [$like]));
        }
        if (!empty($filters['status'])) $query->where('status', $filters['status']);
        if (!empty($filters['facility_node_id'])) {
            $ids = ($filters['include_descendants'] ?? true)
                ? app(FacilityHierarchy::class)->descendants($company, $filters['facility_node_id'])
                : [$filters['facility_node_id']];
            $query->whereIn('facility_node_id', $ids);
        }
        if (!empty($filters['responsible_person_id'])) $query->where('responsible_person_id', $filters['responsible_person_id']);
        if (!empty($filters['ids'])) $query->whereIn('id', $filters['ids']);
        return $query;
    }

    public function export(Request $request, Company $company, CompanyAccess $access, XlsxExport $xlsx): BinaryFileResponse
    {
        $access->member($request, $company);
        $filters = $request->validate([
            'search'=>['nullable','string','max:120'],
            'status'=>['nullable',Rule::in(['active','written_off','disposed'])],
            'facility_node_id'=>['nullable','uuid',Rule::exists('facility_nodes','id')->where('company_id',$company->id)],
            'include_descendants'=>['nullable','boolean'],
            'responsible_person_id'=>['nullable','uuid',Rule::exists('responsible_people','id')->where('company_id',$company->id)],
            'ids'=>['nullable','array','min:1','max:1000'],
            'ids.*'=>['required','uuid','distinct'],
        ]);
        $query = $this->filtered($company, $filters);
        abort_if($query->count() > 50000, 413, 'Export is limited to 50,000 records');
        $rows = (function () use ($query) {
            yield ['Inventory number','Name','Serial','Status','Category','Accounting location','Physical location','Facility','Responsible','Description'];
            foreach ($query->orderBy('id')->cursor() as $asset) {
                yield [$asset->inventory_number,$asset->name,$asset->serial_number,$asset->status,
                    $asset->category?->name,$asset->accountingLocation?->name,
                    $asset->physicalLocation?->name,$asset->facilityNode?->name,
                    $asset->responsiblePerson?->name,$asset->description];
            }
        })();
        return $xlsx->download('inventory-assets.xlsx','Assets',$rows);
    }

    public function move(Request $request, Company $company, CompanyAccess $access, AssetAuditWriter $audit): JsonResponse
    {
        $access->canWrite($request,$company);
        $data=$request->validate([
            'assets'=>['required','array','min:1','max:100'],
            'assets.*.id'=>['required','uuid','distinct'],
            'assets.*.version'=>['required','integer','min:1'],
            'to_location_id'=>['required','uuid',Rule::exists('asset_locations','id')
                ->where('company_id',$company->id)->where('kind','physical')],
            'reason'=>['required','string','min:5','max:1000'],
        ]);
        $changed=DB::transaction(function () use ($request,$company,$data,$audit) {
            $expected=collect($data['assets'])->keyBy('id');
            $assets=$company->assets()->whereIn('id',$expected->keys()->all())
                ->orderBy('id')->lockForUpdate()->get();
            abort_unless($assets->count()===$expected->count(),409,'Asset set changed');
            $destination=$company->assetLocations()->whereKey($data['to_location_id'])
                ->where('kind','physical')->firstOrFail();
            foreach ($assets as $asset) {
                abort_unless($asset->version===$expected[$asset->id]['version']
                    && $asset->status==='active',409,'Asset changed or is inactive');
                abort_if($asset->disposalCase()->exists(),409,'Asset has an active disposal case');
            }
            $count=0;
            foreach ($assets as $asset) {
                if ($asset->physical_location_id===$destination->id) continue;
                $old=$asset->physicalLocation;
                $previous=['physical_location_id'=>$asset->physical_location_id,
                    'physical_location_name'=>$old?->name,'version'=>$asset->version];
                $asset->physical_location_id=$destination->id;
                $asset->version++;
                $asset->save();
                $asset->movements()->create([
                    'id'=>(string) Str::uuid(),'company_id'=>$company->id,
                    'from_location_id'=>$old?->id,'to_location_id'=>$destination->id,
                    'from_location_name'=>$old?->name,'to_location_name'=>$destination->name,
                    'actor_id'=>$request->user()->id,'actor_name'=>$request->user()->name,
                    'reason'=>$data['reason'],'version'=>$asset->version,
                ]);
                $audit->record($asset,$request->user(),'moved',$previous,[
                    'physical_location_id'=>$destination->id,'physical_location_name'=>$destination->name,
                    'version'=>$asset->version,
                ],$data['reason']);
                $count++;
            }
            return $count;
        },3);
        return response()->json(['moved'=>$changed]);
    }

    public function update(Request $request, Company $company, CompanyAccess $access, AssetAuditWriter $audit): JsonResponse
    {
        $access->canWrite($request,$company);
        $data = $request->validate([
            'assets'=>['required','array','min:1','max:100'],
            'assets.*.id'=>['required','uuid','distinct'],
            'assets.*.version'=>['required','integer','min:1'],
            'responsible_person_id'=>['present','nullable','uuid',
                Rule::exists('responsible_people','id')->where('company_id',$company->id)->where('is_active',true)],
            'reason'=>['required','string','min:5','max:1000'],
        ]);
        $updated = DB::transaction(function () use ($company,$request,$data,$audit) {
            $records=collect($data['assets'])->keyBy('id');
            $assets=$company->assets()->whereIn('id',$records->keys()->all())
                ->orderBy('id')->lockForUpdate()->get();
            abort_unless($assets->count()===$records->count(),409,'Asset set changed');
            foreach ($assets as $asset) {
                $expected=$records[$asset->id]['version'];
                abort_unless($asset->version===$expected && $asset->status==='active',409,'Asset changed or is not active');
                abort_if($asset->disposalCase()->exists(),409,'Asset with disposal case cannot be reassigned');
            }
            $changed=0;
            foreach ($assets as $asset) {
                if ($asset->responsible_person_id === $data['responsible_person_id']) continue;
                $before=['responsible_person_id'=>$asset->responsible_person_id,'version'=>$asset->version];
                $asset->responsible_person_id=$data['responsible_person_id'];
                $asset->version++;
                $asset->save();
                $audit->record($asset,$request->user(),'updated',$before,
                    ['responsible_person_id'=>$asset->responsible_person_id,'version'=>$asset->version],$data['reason']);
                $changed++;
            }
            return $changed;
        },3);
        return response()->json(['updated'=>$updated]);
    }
}
