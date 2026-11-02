<?php

namespace App\Console\Commands;

use App\Domain\Inventory\AssetAuditWriter;
use App\Domain\Inventory\AssetDisposalWorkflow;
use App\Domain\Inventory\FacilityHierarchy;
use App\Domain\Inventory\InventorySessionService;
use App\Domain\Inventory\WarehouseLedger;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\InventorySession;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class SeedShowcaseInventory extends Command
{
    protected $signature = 'inventory:demo:seed {--allow-production : Explicitly enable seeding a separate demo company} {--dry-run : Inspect without writing}';
    protected $description = 'Create an isolated, repeatable, synthetic Inventory demo company for presentation';

    public const COMPANY_ID = 'd22f4693-a5ba-4d10-91f8-597ad269ce24';
    public const COMPANY_NAME = '[DEMO] ITP Mining — учебный стенд';

    private function id(): string { return (string) Str::uuid(); }

    private function counts(Company $c): array
    {
        return [
            'company_id' => $c->id,
            'people' => $c->responsiblePeople()->count(),
            'facilities' => $c->facilityNodes()->count(),
            'categories' => $c->assetCategories()->count(),
            'assets' => $c->assets()->count(),
            'warehouses' => $c->facilityNodes()->where('node_type', 'warehouse')->count(),
            'stock_items' => $c->stockItems()->count(),
            'stocktakes' => $c->inventorySessions()->count(),
            'plans' => $c->facilityPlans()->count(),
            'disposals' => $c->assetDisposalCases()->count(),
        ];
    }

    private function samplePdf(): string
    {
        $page = "BT /F1 16 Tf 55 750 Td (DEMO - Inventory Sample Report) Tj ET\n".
            "BT /F1 11 Tf 55 725 Td (Synthetic data - not a legally signed inventory act.) Tj ET\n";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($page)." >>\nstream\n".$page."endstream",
        ];
        $data="%PDF-1.4\n"; $offsets=[];
        foreach ($objects as $i=>$obj) {
            $offsets[]=strlen($data);
            $data.=($i+1)." 0 obj\n".$obj."\nendobj\n";
        }
        $xref=strlen($data);
        $data.="xref\n0 6\n0000000000 65535 f \n";
        foreach ($offsets as $offset) $data.=sprintf("%010d 00000 n \n",$offset);
        return $data."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    private function attachReports(Company $company, User $actor): void
    {
        foreach ($company->inventorySessions()->where('status','completed')->get() as $session) {
            if (DB::table('inventory_session_documents')->where('company_id',$company->id)
                ->where('session_id',$session->id)->exists()) continue;
            $path='inventory-stocktakes/'.$company->id.'/'.$session->id.'/'.$this->id();
            $data=$this->samplePdf();
            if (!Storage::disk('local')->put($path,$data)) throw new RuntimeException('Cannot write synthetic PDF');
            try {
                DB::transaction(function() use ($company,$actor,$session,$path,$data):void {
                    $documentId=$this->id();
                    DB::table('inventory_session_documents')->insert([
                        'id'=>$documentId,'company_id'=>$company->id,'session_id'=>$session->id,
                        'original_name'=>'DEMO-SAMPLE-REPORT.pdf','storage_path'=>$path,
                        'mime_type'=>'application/pdf','file_size'=>strlen($data),
                        'sha256'=>hash('sha256',$data),'uploaded_by'=>$actor->id,
                        'uploaded_by_name'=>$actor->name,'created_at'=>now(),
                    ]);
                    $session->version++; $session->save();
                    $session->events()->create([
                        'id'=>$this->id(),'company_id'=>$company->id,
                        'event_type'=>'document_uploaded','version'=>$session->version,
                        'actor_id'=>$actor->id,'actor_name'=>$actor->name,
                        'after'=>['document_id'=>$documentId,'name'=>'DEMO-SAMPLE-REPORT.pdf',
                            'sha256'=>hash('sha256',$data)],
                    ]);
                });
            } catch (\Throwable $exception) {
                Storage::disk('local')->delete($path);
                throw $exception;
            }
        }
    }

    public function handle(
        FacilityHierarchy $hierarchy, AssetAuditWriter $audit,
        InventorySessionService $sessions, WarehouseLedger $ledger,
        AssetDisposalWorkflow $disposals
    ): int {
        if (app()->environment('production') && !$this->option('allow-production')) {
            $this->error('Production requires explicit --allow-production.');
            return self::FAILURE;
        }
        $adminIds=CompanyMembership::query()->where('role_code','company_admin')
            ->distinct()->pluck('user_id')->unique();
        if ($adminIds->count() !== 1) {
            $this->error('Exactly one existing active administrator must be available; no users or passwords are created.');
            return self::FAILURE;
        }
        $actor=User::query()->whereKey($adminIds->first())->where('is_active',true)->first();
        if (!$actor) { $this->error('Administrator inactive'); return self::FAILURE; }

        $nameCollision=Company::query()->where('name',self::COMPANY_NAME)
            ->where('id','!=',self::COMPANY_ID)->exists();
        $existing=Company::query()->find(self::COMPANY_ID);
        if ($nameCollision || ($existing && $existing->name !== self::COMPANY_NAME)) {
            $this->error('The demo name or fixed ID is already in use; refusing changes.');
            return self::FAILURE;
        }
        if ($this->option('dry-run')) {
            $this->info('DEMO_DRY_RUN=PASS — isolated [DEMO] company, existing data untouched.');
            $this->line(json_encode(['id'=>self::COMPANY_ID,'name'=>self::COMPANY_NAME,
                'exists'=>(bool)$existing],JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }
        if ($existing) {
            if (!$existing->assetCategories()->where('code','D-IT')->exists()
                || $existing->assets()->count() !== 120
                || $existing->inventorySessions()->count() !== 3
                || !CompanyMembership::query()->where('company_id',$existing->id)
                    ->where('user_id',$actor->id)->where('role_code','company_admin')->exists()) {
                $this->error('Existing demo company is not a verified complete fixture; no changes.');
                return self::FAILURE;
            }
            $this->attachReports($existing,$actor);
            $this->info('DEMO_ALREADY_SEEDED=TRUE');
            $this->line(json_encode($this->counts($existing),JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        DB::transaction(function() use($actor,$hierarchy,$audit,$sessions,$ledger,$disposals):void {
            $company=Company::query()->create(['id'=>self::COMPANY_ID,'name'=>self::COMPANY_NAME]);
            CompanyMembership::query()->create([
                'company_id'=>$company->id,'user_id'=>$actor->id,'role_code'=>'company_admin',
            ]);
            DB::table('company_membership_events')->insert([
                'id'=>$this->id(),'company_id'=>$company->id,'target_user_id'=>$actor->id,
                'actor_id'=>$actor->id,'actor_name'=>$actor->name,'event_type'=>'member_added',
                'before_role'=>null,'after_role'=>'company_admin',
                'reason'=>'Authorized, isolated and synthetic demonstration company',
                'created_at'=>now(),
            ]);

            $peopleSpecs=[
                ['Алексей Морозов','Руководитель ИТ'],['Диана Ли','Инженер ИТ'],
                ['Сергей Ким','Системный администратор'],['Марина Орлова','Заведующая складом'],
                ['Илья Романов','Инженер АСУ'],['Елена Васильева','Администратор офиса'],
                ['Тимур Ахметов','Механик'],['Анна Новикова','Координатор проектов'],
                ['Руслан Громов','Начальник участка'],['Ольга Соколова','Специалист закупок'],
                ['Виктор Фёдоров','Инженер связи'],['Кристина Алиева','Оператор оборудования'],
            ];
            $people=[];
            foreach($peopleSpecs as $i=>[$name,$position]) {
                $people[]=$company->responsiblePeople()->create([
                    'id'=>$this->id(),'code'=>sprintf('D-MOL-%02d',$i+1),
                    'name'=>$name,'position'=>$position,'source'=>'manual','is_active'=>true,
                    'email'=>sprintf('demo.person%02d@example.test',$i+1),
                ]);
            }

            $categorySpecs=[
                ['D-IT','Компьютер','monitor'],['D-NB','Ноутбук','laptop'],
                ['D-MN','Монитор','monitor'],['D-PR','Принтер и МФУ','printer'],
                ['D-SR','Сервер','server'],['D-NW','Сетевое оборудование','network'],
                ['D-PH','Смартфон','smartphone'],['D-CM','Камера наблюдения','camera'],
                ['D-UPS','ИБП','hard-drive'],['D-PJ','Проектор','projector'],
                ['D-TL','Измерительный прибор','wrench'],['D-FN','Офисная мебель','armchair'],
                ['D-TB','Планшет','tablet'],['D-RT','Маршрутизатор','router'],
            ];
            $categories=[];
            foreach($categorySpecs as [$code,$name,$icon]) {
                $categories[$code]=$company->assetCategories()->create([
                    'id'=>$this->id(),'code'=>$code,'name'=>$name,
                    'normalized_name'=>mb_strtolower($name),'icon_key'=>$icon,'is_base'=>false,
                ]);
            }
            $physical=[];
            foreach([
                ['D-MAIN','Главный офис (демо)'],['D-OPS','Производственный корпус (демо)'],
                ['D-WH','Склад ИТ (демо)'],['D-MEET','Переговорные (демо)'],
                ['D-REMOTE','Выездные сотрудники (демо)'],
            ] as [$code,$name]) {
                $physical[]=$company->assetLocations()->create([
                    'id'=>$this->id(),'kind'=>'physical','code'=>$code,'name'=>$name,
                ]);
            }
            $balance=$company->assetLocations()->create([
                'id'=>$this->id(),'kind'=>'accounting','code'=>'D-BAL',
                'name'=>'Баланс ITP Mining (учебный)',
            ]);

            $makeNode=function($code,$name,$type,$parent,$person,$order,$address=null,$floor=null)
                use($company,$hierarchy,$actor,$people){
                return $hierarchy->create($company,$actor,[
                    'code'=>$code,'name'=>$name,'node_type'=>$type,'parent_id'=>$parent,
                    'address'=>$address,'floor_number'=>$floor,
                    'responsible_person_id'=>$people[$person]->id,'sort_order'=>$order,
                ]);
            };
            $site=$makeNode('D-SITE','Демонстрационная площадка — Астана','site',null,8,1,
                'г. Астана, учебный объект (адрес вымышлен)');
            $office=$makeNode('D-OFFICE','Административный корпус','building',$site->id,0,1,
                'г. Астана, учебный административный корпус');
            $production=$makeNode('D-PROD','Производственный корпус','building',$site->id,8,2,
                'г. Астана, учебный производственный корпус');
            $pavilion=$makeNode('D-PAV','Выставочный павильон','pavilion',$site->id,7,3);
            $w1=$makeNode('D-WH1','Основной склад ИТ','warehouse',$site->id,3,4);
            $w2=$makeNode('D-WH2','Резервный склад оборудования','warehouse',$office->id,3,8);
            $makeNode('D-WH1-Z','Стеллажный участок A','zone',$w1->id,3,1);
            $makeNode('D-WH1-S','Стеллаж A1','shelf',$w1->id,3,2);
            $makeNode('D-WH2-Z','Резерв комплектующих','zone',$w2->id,3,1);

            $rooms=[];$floors=[];
            foreach([
                ['O',$office,['Приёмная','Бухгалтерия','Отдел закупок','ИТ-служба','Переговорная']],
                ['P',$production,['Диспетчерская','Мастерская АСУ','Инженерный отдел','Операторная','Лаборатория']],
            ] as [$prefix,$building,$labels]) {
                for($f=1;$f<=3;$f++){
                    $floor=$makeNode("D-$prefix-F$f","Этаж $f",'floor',$building->id,
                        $prefix==='O'?0:8,$f,null,$f);
                    $floors[]=$floor;
                    foreach($labels as $index=>$label) {
                        if($f===3 && $index>=3)continue;
                        $rooms[]=$makeNode("D-$prefix-$f-".($index+1),$label.' · '.$f.' эт.',
                            'room',$floor->id,($f*5+$index)%count($people),$index+1);
                    }
                }
            }
            $zones=[];
            foreach(['Экспозиция 1','Демонстрационная зона','Техническая комната'] as $i=>$name) {
                $zones[]=$makeNode('D-PAV-Z'.($i+1),$name,'zone',$pavilion->id,7,$i+1);
            }

            $models=[
                ['Dell OptiPlex','D-IT'],['HP ProBook 450','D-NB'],
                ['Lenovo ThinkPad','D-NB'],['Dell P2422H','D-MN'],
                ['HP LaserJet Pro','D-PR'],['HPE ProLiant DL360','D-SR'],
                ['Cisco Catalyst','D-NW'],['Samsung Galaxy A','D-PH'],
                ['Hikvision DS-2CD','D-CM'],['APC Smart-UPS','D-UPS'],
                ['Epson EB-FH52','D-PJ'],['Fluke 117','D-TL'],
                ['Стол офисный','D-FN'],['iPad 10','D-TB'],
                ['MikroTik hEX','D-RT'],
            ];
            $assets=[];
            for($n=1;$n<=120;$n++){
                [$model,$cat]=$models[($n-1)%count($models)];
                $room=$rooms[($n-1)%count($rooms)];
                $physicalLocation=$n%14===0?$physical[2]:($n%11===0?$physical[3]:$physical[$n%2]);
                $asset=$company->assets()->create([
                    'id'=>$this->id(),'public_id'=>$this->id(),
                    'inventory_number'=>sprintf('DEMO-IT-%04d',$n),
                    'name'=>$model.' · '.sprintf('%03d',$n),
                    'serial_number'=>sprintf('SHOWCASE-%06d',$n),
                    'description'=>'УЧЕБНЫЕ ДАННЫЕ. Запись только для демонстрации Inventory.',
                    'category_id'=>$categories[$cat]->id,
                    'physical_location_id'=>$physicalLocation->id,
                    'accounting_location_id'=>$balance->id,
                    'facility_node_id'=>$room->id,
                    'responsible_person_id'=>$people[($n-1)%count($people)]->id,
                    'status'=>'active',
                ]);
                $asset->refresh();
                $audit->record($asset,$actor,'created',null,[
                    'inventory_number'=>$asset->inventory_number,'name'=>$asset->name,
                    'status'=>$asset->status,'version'=>$asset->version,
                ],'Учебная демонстрационная запись');
                $assets[]=$asset;
            }

            foreach(array_merge([$office,$production,$pavilion,$w1,$w2],$floors,$rooms,$zones) as $facility){
                $children=$company->facilityNodes()->where('parent_id',$facility->id)
                    ->orderBy('sort_order')->get();
                $direct=$company->assets()->where('facility_node_id',$facility->id)
                    ->orderBy('inventory_number')->limit(14)->get();
                if($children->isEmpty() && $direct->isEmpty())continue;
                $plan=$company->facilityPlans()->create([
                    'id'=>$this->id(),'facility_node_id'=>$facility->id,
                    'canvas_width'=>1200,'canvas_height'=>800,'version'=>0,
                ]);
                $elements=[];
                foreach($children as $child)$elements[]=['child_node_id'=>$child->id];
                foreach($direct as $asset)$elements[]=['asset_id'=>$asset->id];
                foreach($elements as $i=>$element){
                    $rect=['x'=>35+($i%5)*226,'y'=>40+intdiv($i,5)*174,'width'=>195,'height'=>135];
                    $placed=$plan->placements()->create([
                        'id'=>$this->id(),'company_id'=>$company->id,...$element,...$rect,
                    ]);
                    $plan->version++;$plan->save();
                    $plan->events()->create([
                        'id'=>$this->id(),'company_id'=>$company->id,
                        'event_type'=>'placed','version'=>$plan->version,
                        'actor_id'=>$actor->id,'actor_name'=>$actor->name,
                        'before'=>null,'after'=>['id'=>$placed->id,...$element,...$rect],
                    ]);
                }
            }

            $stock=[
                'Кабель HDMI 2 м','Патч-корд RJ45','Клавиатура USB','Мышь беспроводная',
                'Флеш-накопитель 64GB','Блок питания 65W','Картридж для принтера',
                'Батареи для ИБП','Набор инструментов','Кабель USB-C','Сетевой фильтр',
                'Модуль SFP','Бумага A4','Крепёж для серверной стойки','SSD 1TB',
                'Оперативная память 16GB',
            ];
            foreach($stock as $i=>$name){
                $category=array_values($categories)[$i%count($categories)];
                $item=$company->stockItems()->create([
                    'id'=>$this->id(),'code'=>sprintf('D-SKU-%03d',$i+1),
                    'name'=>$name,'unit'=>'pcs','category_id'=>$category->id,
                    'minimum_quantity_milli'=>(5+$i%4)*1000,'is_active'=>true,
                ]);
                $ledger->record($company,$actor,[
                    'stock_item_id'=>$item->id,'operation'=>'receipt','warehouse_node_id'=>$w1->id,
                    'quantity'=>(string)(35+$i*2),'reason'=>'Начальное учебное поступление',
                ]);
                if($i%3===0)$ledger->record($company,$actor,[
                    'stock_item_id'=>$item->id,'operation'=>'transfer',
                    'warehouse_node_id'=>$w1->id,'target_warehouse_node_id'=>$w2->id,
                    'quantity'=>(string)(5+$i%4),'reason'=>'Учебное перемещение между складами',
                ]);
            }

            foreach([
                ['ДЕМО · Плановая инвентаризация ИТ — закрыта',array_slice($assets,0,26),19,true],
                ['ДЕМО · Проверка помещений — с расхождениями',array_slice($assets,26,26),13,true],
                ['ДЕМО · Текущая инвентаризация — в работе',array_slice($assets,52,24),8,false],
            ] as $caseIndex=>[$title,$selected,$counted,$finished]){
                $session=$sessions->start($company,$actor,[
                    'name'=>$title,'asset_ids'=>array_map(fn($asset)=>$asset->id,$selected),
                ]);
                foreach($session->items()->orderBy('inventory_number')->get()->take($counted) as $i=>$row){
                    $actual=$row->expected_facility_node_id;
                    if($caseIndex===1 && $i%5===0)$actual=$rooms[($i+2)%count($rooms)]->id;
                    $sessions->countItem($company,$session->id,$row->id,$actor,[
                        'version'=>$row->version,'actual_facility_node_id'=>$actual,
                        'note'=>$caseIndex===1&&$i%5===0
                            ?'Учебное расхождение по фактическому помещению'
                            :'Проверено, демонстрационный подсчёт',
                    ]);
                }
                if($finished)$sessions->finish($company,$session->id,$actor,$session->fresh()->version);
            }
            foreach([103,104,105] as $i){
                $asset=$assets[$i];
                $disposals->create($company,$asset->id,$actor,[
                    'asset_version'=>$asset->version,
                    'reason'=>'ДЕМОНСТРАЦИЯ: условное техническое устаревание, решение не утверждено',
                    'order_number'=>sprintf('DEMO-SP-%03d',$i),'order_date'=>'2026-10-15',
                ]);
            }
        },3);

        $created=Company::query()->findOrFail(self::COMPANY_ID);
        $this->attachReports($created,$actor);
        $this->info('DEMO_SHOWCASE_SEEDED=TRUE');
        $this->line(json_encode($this->counts($created),JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }
}
