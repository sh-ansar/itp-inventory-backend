<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_field_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->uuid('category_id');
            $table->string('code', 60);
            $table->string('label_ru', 160);
            $table->string('label_en', 160)->nullable();
            $table->string('label_ka', 160)->nullable();
            $table->string('field_type', 20);
            $table->json('options')->nullable();
            $table->boolean('required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['company_id','category_id','code'],'asset_fields_tenant_category_code_unique');
            $table->unique(['id','company_id'],'asset_fields_tenant_fk_target');
            $table->foreign(['category_id','company_id'],'asset_fields_category_tenant_fk')
                ->references(['id','company_id'])->on('asset_categories')->restrictOnDelete();
        });
        Schema::create('asset_field_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->uuid('asset_id');
            $table->uuid('field_id');
            $table->json('value')->nullable();
            $table->timestamps();
            $table->unique(['company_id','asset_id','field_id'],'asset_field_value_unique');
            $table->foreign(['asset_id','company_id'],'asset_field_value_asset_tenant_fk')
                ->references(['id','company_id'])->on('assets')->restrictOnDelete();
            $table->foreign(['field_id','company_id'],'asset_field_value_def_tenant_fk')
                ->references(['id','company_id'])->on('asset_field_definitions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_field_values');
        Schema::dropIfExists('asset_field_definitions');
    }
};
