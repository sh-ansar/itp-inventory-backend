<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('industry_catalog_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('kind', 24);
            $table->string('code', 80);
            $table->string('name', 200);
            $table->string('normalized_name', 200);
            $table->uuid('parent_id')->nullable();
            $table->string('parent_key', 40)->default('root');
            $table->string('source', 16)->default('manual');
            $table->string('external_id', 100)->nullable();
            $table->unsignedTinyInteger('precision')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['company_id','kind','code'], 'industry_catalog_company_kind_code_uniq');
            $table->unique(['company_id','kind','normalized_name','parent_key'], 'industry_catalog_company_name_parent_uniq');
            $table->unique(['id','company_id'], 'industry_catalog_company_fk_target');
            $table->foreign(['parent_id','company_id'], 'industry_catalog_parent_company_fk')
                ->references(['id','company_id'])->on('industry_catalog_items')->restrictOnDelete();
            $table->index(['company_id','kind','is_active'], 'industry_catalog_company_kind_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('industry_catalog_items');
    }
};
