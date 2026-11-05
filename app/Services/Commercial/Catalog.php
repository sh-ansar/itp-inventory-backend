<?php

namespace App\Services\Commercial;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class Catalog
{
    public const MODULES = ['assets','disposals','facilities','references','people','warehouses','stocktakes','reports'];

    public function draft(array $input, int $actor): object
    {
        $rules = [
            'code' => ['required','regex:/^[a-z][a-z0-9_-]{1,39}$/'],
            'names' => 'required|array:ru,en,ge',
            'names.ru' => 'required|string|max:120',
            'names.en' => 'required|string|max:120',
            'names.ge' => 'required|string|max:120',
            'description' => 'nullable|string|max:4000',
            'descriptions' => 'nullable|array:ru,en,ge',
            'descriptions.ru' => 'nullable|string|max:4000',
            'descriptions.en' => 'nullable|string|max:4000',
            'descriptions.ge' => 'nullable|string|max:4000',
            'price_minor' => 'required|integer|min:0|max:100000000000',
            'currency' => ['required',Rule::in(['KZT','GEL','USD','EUR'])],
            'period_months' => 'required|integer|min:1|max:120',
            'is_public' => 'required|boolean',
            'display_order' => 'integer|min:-10000|max:10000',
            'limits' => 'required|array:users,companies,storage_bytes,max_file_bytes',
            'limits.users' => 'present|nullable|integer|min:1|max:1000000',
            'limits.companies' => 'present|nullable|integer|min:1|max:1000000',
            'limits.storage_bytes' => 'present|nullable|integer|min:1|max:1000000000000000',
            'limits.max_file_bytes' => 'present|nullable|integer|min:1|max:1000000000000',
            'modules' => 'required|array:'.implode(',',self::MODULES),
        ];
        foreach (self::MODULES as $module) $rules['modules.'.$module] = 'required|boolean';
        $d = Validator::make($input, $rules)->validate();

        return DB::transaction(function () use ($d, $actor) {
            DB::table('inventory_plans')->insertOrIgnore([
                'code' => $d['code'], 'created_at' => now(), 'updated_at' => now(),
            ]);
            $plan = DB::table('inventory_plans')->where('code', $d['code'])->lockForUpdate()->first();
            $version = 1 + (int) DB::table('inventory_plan_versions')->where('plan_id', $plan->id)->max('version');
            $terms = [
                'names' => $d['names'], 'description' => $d['description'] ?? '',
                'descriptions' => $d['descriptions'] ?? [
                    'ru'=>$d['description'] ?? '', 'en'=>$d['description'] ?? '',
                    'ge'=>$d['description'] ?? '',
                ],
                'price_minor' => (int)$d['price_minor'], 'currency' => $d['currency'],
                'period_months' => (int)$d['period_months'], 'limits' => $d['limits'],
                'modules' => $d['modules'],
            ];
            $json = json_encode($terms, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $id = DB::table('inventory_plan_versions')->insertGetId([
                'plan_id' => $plan->id, 'version' => $version, 'status' => 'draft',
                'is_public' => $d['is_public'], 'display_order' => $d['display_order'] ?? 0,
                'terms' => $json, 'sha256' => hash('sha256',$json),
                'created_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
            ]);
            Access::audit($actor, null, 'plan.draft', 'plan:'.$id, ['code'=>$d['code'],'version'=>$version]);
            return $this->find($id);
        }, 3);
    }

    public function find(int $id): object
    {
        $row = DB::table('inventory_plan_versions as v')
            ->join('inventory_plans as p','p.id','=','v.plan_id')
            ->select('v.*','p.code')->where('v.id',$id)->first();
        abort_unless($row, 404);
        $row->terms = json_decode($row->terms, true, 512, JSON_THROW_ON_ERROR);
        return $row;
    }

    public function list(bool $public = false): array
    {
        $query = DB::table('inventory_plan_versions as v')
            ->join('inventory_plans as p','p.id','=','v.plan_id')
            ->select('v.*','p.code')->orderBy('v.display_order')->orderByDesc('v.id');
        $all = $query->get()->map(function($row) {
            $row->terms = json_decode($row->terms,true,512,JSON_THROW_ON_ERROR);
            return $row;
        })->all();
        if (!$public) return $all;
        // Hide retired plans even when an older published version remains.
        $latest = [];
        foreach ($all as $row) {
            if (!isset($latest[$row->code]) || $row->version > $latest[$row->code]->version) {
                if (in_array($row->status,['published','archived'],true)) $latest[$row->code] = $row;
            }
        }
        return array_values(array_filter($latest, fn($p) =>
            $p->status === 'published' && $p->is_public && $p->code !== 'legacy'));
    }

    public function transition(int $id, string $status, int $actor): object
    {
        abort_unless(in_array($status,['published','archived'],true),422);
        DB::transaction(function () use ($id,$status,$actor) {
            $row = DB::table('inventory_plan_versions')->where('id',$id)->lockForUpdate()->first();
            abort_unless($row,404);
            abort_unless($row->status === ($status === 'published' ? 'draft' : 'published'),409);
            DB::table('inventory_plan_versions')->where('id',$id)->update([
                'status'=>$status,'updated_at'=>now(),
            ]);
            Access::audit($actor,null,'plan.'.$status,'plan:'.$id);
        }, 3);
        return $this->find($id);
    }

    public function assign(string $company, int $version, ?string $ends, int $actor): void
    {
        DB::transaction(function () use ($company,$version,$ends,$actor) {
            abort_unless(DB::table('companies')->where('id',$company)->exists(),404);
            $plan = $this->find($version);
            abort_unless($plan->status==='published',409);
            DB::table('inventory_company_subscriptions')->updateOrInsert(
                ['company_id'=>$company],
                ['plan_version_id'=>$plan->id,
                 'snapshot'=>json_encode(['code'=>$plan->code,'version'=>$plan->version,'terms'=>$plan->terms],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                 'status'=>'active','starts_at'=>now(),'ends_at'=>$ends,'updated_at'=>now(),'created_at'=>now()]
            );
            Access::audit($actor,$company,'subscription.assigned','plan:'.$plan->id,['ends_at'=>$ends]);
        },3);
    }
}
