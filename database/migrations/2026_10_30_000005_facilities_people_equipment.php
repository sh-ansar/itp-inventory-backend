<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('responsible_people', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 80);
            $table->string('name', 255);
            $table->string('position', 200)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('source', 24)->default('manual');
            $table->string('external_employee_id', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['company_id', 'code'], 'inventory_person_company_code_unique');
            $table->unique(['id', 'company_id'], 'inventory_person_company_fk_target');
            $table->unique(['company_id', 'source', 'external_employee_id'], 'inv_person_external_unique');
            $table->index(['company_id', 'name'], 'inv_person_name_idx');
        });

        Schema::create('facility_nodes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->string('code', 80);
            $table->string('name', 200);
            $table->string('node_type', 24);
            $table->string('address', 500)->nullable();
            $table->integer('floor_number')->nullable();
            $table->foreignUuid('responsible_person_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['company_id', 'code'], 'facility_company_code_unique');
            $table->unique(['id', 'company_id'], 'facility_company_fk_target');
            $table->foreign(['parent_id', 'company_id'], 'facility_parent_same_tenant_fk')
                ->references(['id', 'company_id'])->on('facility_nodes')->restrictOnDelete();
            $table->foreign(['responsible_person_id', 'company_id'], 'facility_person_same_tenant_fk')
                ->references(['id', 'company_id'])->on('responsible_people')->restrictOnDelete();
            $table->index(['company_id', 'parent_id', 'sort_order'], 'facility_parent_order_idx');
            $table->index(['company_id', 'node_type'], 'facility_kind_idx');
        });

        Schema::create('facility_node_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->uuid('facility_node_id');
            $table->string('event_type', 32);
            $table->unsignedInteger('version');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 255);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('reason', 1000)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign(['facility_node_id', 'company_id'], 'facility_event_same_tenant_fk')
                ->references(['id', 'company_id'])->on('facility_nodes')->restrictOnDelete();
            $table->unique(['facility_node_id', 'version'], 'facility_event_version_unique');
        });

        Schema::table('asset_categories', function (Blueprint $table): void {
            $table->string('normalized_name', 200)->nullable();
            $table->string('icon_key', 48)->default('box');
            $table->string('icon_path', 255)->nullable();
            $table->string('base_key', 80)->nullable();
            $table->boolean('is_base')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->unique(['company_id', 'normalized_name'], 'category_company_name_unique');
            $table->unique(['company_id', 'base_key'], 'category_company_base_unique');
        });

        Schema::table('assets', function (Blueprint $table): void {
            $table->foreignUuid('facility_node_id')->nullable();
            $table->foreignUuid('responsible_person_id')->nullable();
            $table->foreign(['facility_node_id', 'company_id'], 'asset_facility_company_fk')
                ->references(['id', 'company_id'])->on('facility_nodes')->restrictOnDelete();
            $table->foreign(['responsible_person_id', 'company_id'], 'asset_person_company_fk')
                ->references(['id', 'company_id'])->on('responsible_people')->restrictOnDelete();
            $table->index(['company_id', 'facility_node_id', 'status'], 'asset_facility_index');
            $table->index(['company_id', 'responsible_person_id'], 'asset_person_index');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->dropForeign('asset_facility_company_fk');
            $table->dropForeign('asset_person_company_fk');
            $table->dropIndex('asset_facility_index');
            $table->dropIndex('asset_person_index');
            $table->dropColumn(['facility_node_id', 'responsible_person_id']);
        });
        Schema::table('asset_categories', function (Blueprint $table): void {
            $table->dropUnique('category_company_name_unique');
            $table->dropUnique('category_company_base_unique');
            $table->dropColumn(['normalized_name', 'icon_key', 'icon_path', 'base_key', 'is_base', 'version']);
        });
        Schema::dropIfExists('facility_node_events');
        Schema::dropIfExists('facility_nodes');
        Schema::dropIfExists('responsible_people');
    }
};
