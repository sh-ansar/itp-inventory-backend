<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_disposal_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('asset_id');
            $table->string('status', 24)->default('draft');
            $table->text('reason');
            $table->string('order_number', 120)->nullable();
            $table->date('order_date')->nullable();
            $table->string('disposal_act_number', 120)->nullable();
            $table->date('disposal_date')->nullable();
            $table->string('disposal_method', 36)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['asset_id', 'company_id'], 'disposal_case_asset_once');
            $table->unique(['id', 'company_id'], 'disposal_case_tenant_target');
            $table->foreign(['asset_id', 'company_id'], 'disposal_case_asset_company_fk')
                ->references(['id', 'company_id'])->on('assets')->restrictOnDelete();
            $table->index(['company_id', 'status', 'updated_at'], 'disposal_case_queue_index');
        });

        Schema::create('asset_disposal_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('asset_disposal_case_id');
            $table->string('document_type', 36);
            $table->string('original_name', 255);
            $table->string('disk_path', 320)->unique();
            $table->string('mime_type', 100);
            $table->unsignedInteger('bytes');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('uploaded_by_name', 255);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['asset_disposal_case_id', 'company_id'], 'disposal_doc_case_company_fk')
                ->references(['id', 'company_id'])->on('asset_disposal_cases')->restrictOnDelete();
            $table->index(['company_id', 'asset_disposal_case_id', 'document_type'], 'disposal_doc_by_type_index');
        });

        Schema::create('asset_disposal_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('asset_disposal_case_id');
            $table->string('event_type', 36);
            $table->unsignedInteger('version');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 255);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['asset_disposal_case_id', 'company_id'], 'disposal_event_case_company_fk')
                ->references(['id', 'company_id'])->on('asset_disposal_cases')->restrictOnDelete();
            $table->unique(['asset_disposal_case_id', 'version'], 'disposal_event_case_version_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE asset_disposal_cases ADD CONSTRAINT disposal_status_chk CHECK (status IN ('draft','submitted','approved','disposed'))");
            DB::statement("ALTER TABLE asset_disposal_documents ADD CONSTRAINT disposal_document_type_chk CHECK (document_type IN ('write_off_order','disposal_act','supporting'))");
            DB::statement("ALTER TABLE asset_disposal_cases ADD CONSTRAINT disposal_method_chk CHECK (disposal_method IS NULL OR disposal_method IN ('recycling','destruction','waste_transfer','other'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_disposal_events');
        Schema::dropIfExists('asset_disposal_documents');
        Schema::dropIfExists('asset_disposal_cases');
    }
};
