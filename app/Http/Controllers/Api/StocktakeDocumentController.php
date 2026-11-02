<?php

namespace App\Http\Controllers\Api;

use App\Domain\Inventory\CompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class StocktakeDocumentController extends Controller
{
    public function index(Request $request, Company $company, string $session, CompanyAccess $access): JsonResponse
    {
        $access->member($request,$company);
        $company->inventorySessions()->whereKey($session)->firstOrFail();
        return response()->json(['data'=>DB::table('inventory_session_documents')
            ->where('company_id',$company->id)->where('session_id',$session)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get(['id','original_name','mime_type','file_size','sha256','uploaded_by_name','created_at'])]);
    }

    public function store(Request $request, Company $company, string $session, CompanyAccess $access): JsonResponse
    {
        $access->canWrite($request,$company);
        $data=$request->validate([
            'version'=>['required','integer','min:1'],
            'file'=>['required','file','max:10240','mimes:pdf,jpg,jpeg,png'],
        ]);
        $file=$data['file'];
        $bytes=file_get_contents($file->getRealPath());
        $mime=$file->getMimeType();
        if ($mime==='application/pdf' && !str_starts_with($bytes,'%PDF-')) {
            throw ValidationException::withMessages(['file'=>['Invalid PDF signature']]);
        }
        if (in_array($mime,['image/png','image/jpeg'],true) && !@getimagesizefromstring($bytes)) {
            throw ValidationException::withMessages(['file'=>['Invalid image']]);
        }
        if (!in_array($mime,['application/pdf','image/png','image/jpeg'],true)) {
            throw ValidationException::withMessages(['file'=>['Unsupported document']]);
        }
        $id=(string) Str::uuid();
        $path='inventory-stocktakes/'.$company->id.'/'.$session.'/'.$id;
        abort_unless(Storage::disk('local')->put($path,$bytes),507,'Unable to store document');
        try {
            $row=DB::transaction(function () use ($request,$company,$session,$data,$file,$bytes,$mime,$id,$path) {
                $stocktake=$company->inventorySessions()->whereKey($session)->lockForUpdate()->firstOrFail();
                abort_unless($stocktake->version===(int) $data['version'],409,'Stocktake changed');
                abort_unless($stocktake->status==='completed',409,'Only completed stocktakes accept signed reports');
                $original=mb_substr(basename(str_replace('\\','/',$file->getClientOriginalName())),0,180);
                $record=[
                    'id'=>$id,'company_id'=>$company->id,'session_id'=>$session,
                    'original_name'=>$original,'storage_path'=>$path,'mime_type'=>$mime,
                    'file_size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),
                    'uploaded_by'=>$request->user()->id,'uploaded_by_name'=>$request->user()->name,
                    'created_at'=>now(),
                ];
                DB::table('inventory_session_documents')->insert($record);
                $stocktake->version++;
                $stocktake->save();
                $stocktake->events()->create([
                    'id'=>(string)Str::uuid(),'company_id'=>$company->id,
                    'event_type'=>'document_uploaded','version'=>$stocktake->version,
                    'actor_id'=>$request->user()->id,'actor_name'=>$request->user()->name,
                    'after'=>['document_id'=>$id,'sha256'=>$record['sha256'],'name'=>$original],
                ]);
                return $record;
            },3);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        unset($row['storage_path']);
        return response()->json(['data'=>$row],201);
    }

    public function download(Request $request,Company $company,string $session,string $document,CompanyAccess $access): StreamedResponse
    {
        $access->member($request,$company);
        $company->inventorySessions()->whereKey($session)->firstOrFail();
        $record=DB::table('inventory_session_documents')->where('company_id',$company->id)
            ->where('session_id',$session)->where('id',$document)->first();
        abort_unless($record,404);
        abort_unless(Storage::disk('local')->exists($record->storage_path),404);
        return Storage::disk('local')->download($record->storage_path,$record->original_name,[
            'Content-Type'=>$record->mime_type,'Cache-Control'=>'private, no-store',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }
}
