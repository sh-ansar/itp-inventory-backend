<?php

namespace App\Http\Controllers\Api;

use App\Domain\Inventory\CompanyAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\AssetResource;
use App\Models\Company;
use Illuminate\Http\Request;

final class AssetQrController extends Controller
{
    public function show(
        Request $request, Company $company, string $publicId, CompanyAccess $access
    ): AssetResource {
        $access->member($request, $company);
        // The QR contains only a random public UUID. It is not an auth token.
        // Resolve under the chosen tenant and never expose archived/foreign assets.
        abort_unless(
            preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/iD', $publicId),
            404
        );
        $asset = $company->assets()->where('public_id', $publicId)
            ->with(['category', 'accountingLocation', 'physicalLocation'])
            ->firstOrFail();
        return new AssetResource($asset);
    }
}