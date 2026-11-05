<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Http\Controllers\Controller;
use App\Services\Commercial\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class SiteContentController extends Controller
{
    // Keys are whitelist-only; values are plain-text Vue interpolations, never rendered as HTML.
    public const KEYS = [
        'heroTitle','heroSub','heroText','cardsTitle','cardsDescription',
        'moduleTitle','moduleSubtitle','demoTitle','demoDescription',
        'scanTitle','scanText','mapTitle','mapText','summaryTitle','summaryText',
        'howTitle','cta','planTitle','planText','privacyNotice',
    ];

    private function locale(Request $request): string
    {
        $data=$request->validate(['locale'=>['required',Rule::in(['ru','en','ge'])]]);
        return $data['locale'];
    }

    public function publicContent(Request $request): JsonResponse
    {
        $locale=$this->locale($request);
        return response()->json(['data'=>$this->content($locale)])
            ->header('Cache-Control','private, max-age=30');
    }

    public function show(Request $request): JsonResponse
    {
        Access::need($request,'site.read');
        return response()->json(['data'=>$this->content($this->locale($request))]);
    }

    private function content(string $locale): array
    {
        return DB::table('inventory_site_content')->where('locale',$locale)
            ->whereIn('key',self::KEYS)->pluck('value','key')->all();
    }

    public function save(Request $request): JsonResponse
    {
        Access::need($request,'site.write');
        $payload=$request->validate([
            'locale'=>['required',Rule::in(['ru','en','ge'])],
            'values'=>'required|array',
        ]);
        $values=$payload['values'];
        abort_if(count($values)>count(self::KEYS),422,'Too many site fields');
        foreach($values as $key=>$value) {
            abort_unless(is_string($key)&&in_array($key,self::KEYS,true),422,'Unsupported site content key');
            abort_unless($value===null||(is_string($value)&&mb_strlen($value)<=1000),422,'Site text must be up to 1000 characters');
            if(is_string($value))abort_if(preg_match('/<[a-z][^>]*>/iu',$value)===1,422,'HTML is not allowed in website text');
        }
        $actor=(int)$request->user()->id;
        DB::transaction(function()use($payload,$values,$actor) {
            foreach($values as $key=>$value) {
                $value=is_string($value)?trim($value):'';
                $where=['locale'=>$payload['locale'],'key'=>$key];
                if($value==='') {
                    DB::table('inventory_site_content')->where($where)->delete();
                } else {
                    DB::table('inventory_site_content')->updateOrInsert($where,[
                        'value'=>$value,'updated_by'=>$actor,'updated_at'=>now(),
                        'created_at'=>now(),
                    ]);
                }
            }
            Access::audit($actor,null,'site.content.saved','locale:'.$payload['locale'],[
                'fields'=>array_keys($values),
            ]);
        },3);
        return response()->json(['data'=>$this->content($payload['locale'])]);
    }
}
