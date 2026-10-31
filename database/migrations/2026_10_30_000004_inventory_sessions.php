<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('name', 200);
            $table->string('status', 24)->default('in_progress');
            $table->string('scope_type', 24)->default('all');
            $table->uuid('scope_location_id')->nullable();
            $table->string('scope_location_name', 200)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('counted_items')->default(0);
            $table->unsignedInteger('discrepancies')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name', 255);
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('completed_by_name', 255)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'company_id'], 'inv_sessions_id_company_unique');
            $table->foreign(['scope_location_id', 'company_id'], 'inv_session_location_company_fk')
                ->references(['id', 'company_id'])->on('asset_locations')->restrictOnDelete();
            $table->index(['company_id', 'status', 'created_at'], 'inv_sessions_company_status_idx');
        });

        Schema::create('inventory_session_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->uuid('session_id');
            $table->uuid('asset_id');
            $table->string('inventory_number', 100);
            $table->string('name', 255);
            $table->uuid('expected_location_id')->nullable();
            $table->string('expected_location_name', 200)->nullable();
            $table->uuid('actual_location_id')->nullable();
            $table->string('actual_location_name', 200)->nullable();
            $table->boolean('is_counted')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->text('note')->nullable();
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('counted_by_name', 255)->nullable();
            $table->timestamp('counted_at')->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'asset_id'], 'inv_items_session_asset_unique');
            $table->unique(['id', 'company_id'], 'inv_items_id_company_unique');
            $table->foreign(['session_id', 'company_id'], 'inv_items_session_company_fk')
                ->references(['id', 'company_id'])->on('inventory_sessions')->restrictOnDelete();
            $table->foreign(['asset_id', 'company_id'], 'inv_items_asset_company_fk')
                ->references(['id', 'company_id'])->on('assets')->restrictOnDelete();
            $table->foreign(['expected_location_id', 'company_id'], 'inv_items_expected_company_fk')
                ->references(['id', 'company_id'])->on('asset_locations')->restrictOnDelete();
            $table->foreign(['actual_location_id', 'company_id'], 'inv_items_actual_company_fk')
                ->references(['id', 'company_id'])->on('asset_locations')->restrictOnDelete();
            $table->index(['company_id', 'session_id', 'is_counted'], 'inv_items_counted_idx');
        });

        Schema::create('inventory_session_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->uuid('session_id');
            $table->uuid('item_id')->nullable();
            $table->string('event_type', 32);
            $table->unsignedInteger('version');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 255);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign(['session_id', 'company_id'], 'inv_events_session_company_fk')
                ->references(['id', 'company_id'])->on('inventory_sessions')->restrictOnDelete();
            $table->foreign(['item_id', 'company_id'], 'inv_events_item_company_fk')
                ->references(['id', 'company_id'])->on('inventory_session_items')->restrictOnDelete();
            $table->unique(['session_id', 'version'], 'inv_events_session_version_unique');
            $table->index(['company_id', 'session_id', 'created_at'], 'inv_events_session_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_session_events');
        Schema::dropIfExists('inventory_session_items');
        Schema::dropIfExists('inventory_sessions');
    }
};
