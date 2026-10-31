<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_disposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->uuid('asset_id');
            $table->string('stage', 24); // write_off -> disposal (two immutable decisions)
            $table->string('document_number', 100);
            $table->date('document_date');
            $table->text('reason');
            $table->string('disposal_method', 200)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 255);
            $table->unsignedInteger('asset_version');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['id', 'company_id'], 'asset_disposal_company_unique');
            $table->unique(['asset_id', 'stage'], 'asset_disposal_stage_unique');
            $table->foreign(['asset_id', 'company_id'], 'asset_disposal_asset_company_fk')
                ->references(['id', 'company_id'])->on('assets')->restrictOnDelete();
            $table->index(['company_id', 'stage', 'created_at'], 'asset_disposal_company_stage_idx');
        });

        Schema::create('asset_disposal_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->uuid('disposal_id');
            $table->string('original_name', 255);
            $table->string('storage_path', 400)->unique();
            $table->string('mime_type', 60);
            $table->unsignedBigInteger('file_size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('uploaded_by_name', 255);
            $table->timestamp('created_at')->useCurrent();
            $table->foreign(['disposal_id', 'company_id'], 'disposal_document_tenant_fk')
                ->references(['id', 'company_id'])->on('asset_disposals')->restrictOnDelete();
            $table->index(['company_id', 'disposal_id', 'created_at'], 'disposal_document_history_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_disposal_documents');
        Schema::dropIfExists('asset_disposals');
    }
};
