<?php

namespace App\Http\Controllers\Api;

use App\Domain\Inventory\CompanyAccess;
use App\Domain\Inventory\XlsxExport;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class StocktakeXlsxController extends Controller
{
    public function export(Request $request,Company $company,string $session,CompanyAccess $access,XlsxExport $xlsx): BinaryFileResponse
    {
        $access->member($request,$company);
        $model=$company->inventorySessions()->whereKey($session)->firstOrFail();
        abort_if($model->total_items>50000,413,'Report exceeds 50,000 records');
        $rows=(function () use ($model) {
            yield ['Inventory number','Asset','Expected location','Actual location',
                'Expected facility','Actual facility','Counted','Note','Counted by'];
            foreach($model->items()->orderBy('id')->cursor() as $item) {
                yield [$item->inventory_number,$item->name,$item->expected_location_name,
                    $item->actual_location_name,$item->expected_facility_node_name,
                    $item->actual_facility_node_name,$item->is_counted?'Yes':'No',
                    $item->note,$item->counted_by_name];
            }
        })();
        return $xlsx->download('inventory-stocktake.xlsx','Stocktake',$rows);
    }
}
