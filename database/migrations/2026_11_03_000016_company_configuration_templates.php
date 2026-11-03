<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_company_settings', function (Blueprint $table): void {
            $table->foreignUuid('company_id')->primary()->constrained('companies')->restrictOnDelete();
            $table->string('legal_name',255)->nullable();
            $table->string('country_code',2)->default('KZ');
            $table->string('timezone',80)->default('Asia/Almaty');
            $table->string('currency',3)->default('KZT');
            $table->string('asset_number_prefix',30)->default('INV');
            $table->string('document_number_prefix',30)->default('DOC');
            $table->string('default_locale',2)->default('ru');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('inventory_document_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('kind',24);
            $table->string('locale',2);
            $table->unsignedInteger('revision');
            $table->string('title',200);
            $table->text('body');
            $table->string('status',16)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id','kind','locale','revision'],'inv_template_company_kind_locale_revision_unique');
            $table->index(['company_id','kind','locale','status'],'inv_template_company_kind_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_document_templates');
        Schema::dropIfExists('inventory_company_settings');
    }
};
