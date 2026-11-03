<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fail safely before DDL if existing tenant data contains duplicates.
        $seen = [];
        $normalized = [];
        foreach (DB::table('asset_locations')->orderBy('id')->get(['id', 'company_id', 'kind', 'name']) as $row) {
            $name = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $row->name)));
            $key = $row->company_id.'|'.$row->kind.'|'.$name;
            if (isset($seen[$key])) {
                throw new RuntimeException('Conflicting location names in company '.$row->company_id
                    .' and kind '.$row->kind.'; reconcile before migration');
            }
            $seen[$key] = true;
            $normalized[$row->id] = $name;
        }
        Schema::table('asset_locations', function (Blueprint $table): void {
            $table->string('normalized_name', 200)->nullable();
        });
        foreach ($normalized as $id => $name) {
            DB::table('asset_locations')->where('id', $id)->update(['normalized_name' => $name]);
        }
        Schema::table('asset_locations', function (Blueprint $table): void {
            $table->unique(['company_id', 'kind', 'normalized_name'], 'inv_location_tenant_kind_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('asset_locations', function (Blueprint $table): void {
            $table->dropUnique('inv_location_tenant_kind_name_unique');
            $table->dropColumn('normalized_name');
        });
    }
};
