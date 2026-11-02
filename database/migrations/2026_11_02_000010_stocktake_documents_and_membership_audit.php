<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_session_documents', function(Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('session_id');
            $table->string('original_name',180);
            $table->string('storage_path',400)->unique();
            $table->string('mime_type',60);
            $table->unsignedBigInteger('file_size');
            $table->string('sha256',64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('uploaded_by_name',255);
            $table->timestamp('created_at')->useCurrent();
            $table->foreign(['session_id','company_id'],'stocktake_docs_tenant_fk')
                ->references(['id','company_id'])->on('inventory_sessions')->restrictOnDelete();
            $table->index(['company_id','session_id','created_at'],'stocktake_docs_history_idx');
        });
        Schema::create('company_membership_events', function(Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->foreignId('target_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name',255);
            $table->string('event_type',32);
            $table->string('before_role',48)->nullable();
            $table->string('after_role',48)->nullable();
            $table->text('reason');
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->index(['company_id','target_user_id','created_at'],'membership_events_company_idx');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('company_membership_events');
        Schema::dropIfExists('inventory_session_documents');
    }
};
