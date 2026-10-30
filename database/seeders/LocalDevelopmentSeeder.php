<?php

namespace Database\Seeders;

use App\Domain\Inventory\AssetAuditWriter;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

final class LocalDevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment('local') || config('database.default') !== 'sqlite') {
            throw new RuntimeException('QA fixture is permitted only on local SQLite');
        }
        // Auto-generate a synthetic-only password and keep it in gitignored private storage.
        // Never reuse real account credentials and never print this value.
        $secretPath = storage_path('app/private/local-inventory-qa.secret');
        if (!is_file($secretPath)) {
            $generated = bin2hex(random_bytes(24));
            file_put_contents($secretPath, $generated, LOCK_EX);
        }
        $password = trim((string) file_get_contents($secretPath));
        if (strlen($password) < 24) throw new RuntimeException('Invalid local QA secret');

        $mining = Company::query()->firstOrCreate(['name'=>'Inventory QA - Mining'],['id'=>(string) Str::uuid()]);
        $solutions = Company::query()->firstOrCreate(['name'=>'Inventory QA - Solutions'],['id'=>(string) Str::uuid()]);

        $admin = User::query()->updateOrCreate(['email'=>'inventory.qa@example.test'],[
            'name'=>'Inventory QA Admin', 'password'=>Hash::make($password), 'is_active'=>true,
        ]);
        $viewer = User::query()->updateOrCreate(['email'=>'inventory.viewer@example.test'],[
            'name'=>'Inventory QA Viewer', 'password'=>Hash::make($password), 'is_active'=>true,
        ]);
        $admin->companyMemberships()->firstOrCreate(['company_id'=>$mining->id],['role_code'=>'company_admin']);
        $admin->companyMemberships()->firstOrCreate(['company_id'=>$solutions->id],['role_code'=>'inventory_manager']);
        $viewer->companyMemberships()->firstOrCreate(['company_id'=>$mining->id],['role_code'=>'viewer']);

        $audit = app(AssetAuditWriter::class);
        foreach ([[$mining,52,'MIN'],[$solutions,8,'SOL']] as [$company,$count,$prefix]) {
            $category = $company->assetCategories()->firstOrCreate(['code'=>'IT'],[
                'id'=>(string) Str::uuid(), 'name'=>'IT equipment',
            ]);
            $accounting = $company->assetLocations()->firstOrCreate(['kind'=>'accounting','code'=>'BAL'],[
                'id'=>(string) Str::uuid(), 'name'=>'Accounting balance',
            ]);
            $physical = $company->assetLocations()->firstOrCreate(['kind'=>'physical','code'=>'OFF'],[
                'id'=>(string) Str::uuid(), 'name'=>'QA office',
            ]);
            for ($number=1; $number<=$count; $number++) {
                $inventoryNumber = sprintf('%s-%04d', $prefix, $number);
                $existing = $company->assets()->where('inventory_number',$inventoryNumber)->first();
                if ($existing) continue;
                $asset = $company->assets()->create([
                    'id'=>(string) Str::uuid(), 'public_id'=>(string) Str::uuid(),
                    'inventory_number'=>$inventoryNumber,
                    'name'=>'QA laptop '.sprintf('%02d', $number),
                    'serial_number'=>'QA-'.sprintf('%05d',$number),
                    'category_id'=>$category->id,
                    'accounting_location_id'=>$accounting->id,
                    'physical_location_id'=>$physical->id,
                    'description'=>'Synthetic development record. Not a real company asset.',
                    'status'=>'active',
                ]);
                $asset->refresh();
                $audit->record($asset,$admin,'created',null,[
                    'inventory_number'=>$asset->inventory_number,'name'=>$asset->name,
                    'status'=>$asset->status,'version'=>$asset->version,
                ]);
            }
        }
    }
}