<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCommercialEntitlement
{
    /**
     * Protect subscribed tenants without altering legacy Inventory companies:
     * no subscription record means the original access policy remains intact.
     *
     * Never share Portal's subscription tables, users or browser sessions.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Laravel SubstituteBindings may already convert the UUID route parameter to Company.
        $parameter=$request->route('company');
        $company=$parameter instanceof \App\Models\Company
            ? (string)$parameter->getKey() : (string)$parameter;
        abort_unless($company!=='' && $request->user(),403);
        // Preserve the existing 404 concealment for companies outside the user's tenancy.
        abort_unless($request->user()->companyMemberships()
            ->where('company_id',$company)->exists(),404);

        $row=DB::table('inventory_company_subscriptions')->where('company_id',$company)->first();
        if (!$row)return $next($request);

        if ($row->status!=='active'
          || ($row->ends_at && now()->greaterThanOrEqualTo($row->ends_at))) {
            abort(402,'The Inventory subscription is inactive or expired.');
        }

        $snapshot=json_decode($row->snapshot,true)?:[];
        $terms=$snapshot['terms']??[];
        $limits=$terms['limits']??[];
        $modules=$terms['modules']??[];
        $path=explode('/',trim($request->path(),'/'));
        // api/v1/companies/{uuid}/{resource}
        $resource=$path[4]??'';
        $suffix=$path[5]??'';
        $module=match($resource) {
            'assets' => $suffix==='disposal-case' ? 'disposals' : 'assets',
            'disposal-cases' => 'disposals',
            'inventory-sessions' => 'stocktakes',
            'stock-items','stock-balances','stock-transactions' => 'warehouses',
            'facility-nodes' => 'facilities',
            'responsible-people' => 'people',
            'categories','reference-data','equipment-presets',
            'industry-catalogs','locations' => 'references',
            'reports' => 'reports',
            default => null,
        };
        // Multi-segment disposals use /assets/{asset}/disposal-case
        if ($resource==='assets' && in_array('disposal-case',$path,true)) $module='disposals';
        if($module!==null && array_key_exists($module,$modules) && !$modules[$module])
            abort(403,'Module is not enabled in your Inventory plan: '.$module);

        if($resource==='members' && $request->isMethod('POST')
            && isset($limits['users'])) {
            $members=DB::table('company_memberships')->where('company_id',$company)->count();
            abort_if($members >= (int)$limits['users'],409,'User limit has been reached.');
        }

        // Max file bytes is not a count of HTTP requests: enforce only actual uploaded bytes.
        if (isset($limits['max_file_bytes'])) {
            $max=(int)$limits['max_file_bytes'];
            $check=function(array $files) use (&$check,$max):void {
                foreach($files as $file) {
                    if(is_array($file)){$check($file);continue;}
                    if($file instanceof \Illuminate\Http\UploadedFile
                       && $file->getSize()>$max)abort(413,'Plan file-size limit exceeded.');
                }
            };
            $check($request->allFiles());
        }
        return $next($request);
    }
}
