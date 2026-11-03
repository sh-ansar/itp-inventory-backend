<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->foreignUuid('manufacturer_id')->nullable();
            $table->foreignUuid('model_id')->nullable();
            $table->foreign(['manufacturer_id', 'company_id'], 'asset_manufacturer_same_tenant_fk')
                ->references(['id', 'company_id'])->on('industry_catalog_items')->restrictOnDelete();
            $table->foreign(['model_id', 'company_id'], 'asset_model_same_tenant_fk')
                ->references(['id', 'company_id'])->on('industry_catalog_items')->restrictOnDelete();
        });
        Schema::table('responsible_people', function (Blueprint $table): void {
            $table->foreignUuid('department_id')->nullable();
            $table->foreign(['department_id', 'company_id'], 'person_department_same_tenant_fk')
                ->references(['id', 'company_id'])->on('industry_catalog_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('responsible_people', function (Blueprint $table): void {
            $table->dropForeign('person_department_same_tenant_fk');
            $table->dropColumn('department_id');
        });
        Schema::table('assets', function (Blueprint $table): void {
            $table->dropForeign('asset_model_same_tenant_fk');
            $table->dropForeign('asset_manufacturer_same_tenant_fk');
            $table->dropColumn(['manufacturer_id', 'model_id']);
        });
    }
};
