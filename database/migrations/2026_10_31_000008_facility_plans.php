<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignUuid('facility_node_id');
            $table->unsignedInteger('canvas_width')->default(1200);
            $table->unsignedInteger('canvas_height')->default(800);
            $table->unsignedInteger('version')->default(1);
            $table->string('background_path', 255)->nullable();
            $table->timestamps();
            $table->unique(['facility_node_id', 'company_id'], 'plan_node_company_unique');
            $table->unique(['id', 'company_id'], 'plan_company_target_unique');
            $table->foreign(['facility_node_id', 'company_id'], 'plan_node_company_fk')
                ->references(['id', 'company_id'])->on('facility_nodes')->restrictOnDelete();
        });

        Schema::create('facility_plan_placements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('facility_plan_id');
            $table->uuid('asset_id')->nullable();
            $table->uuid('child_node_id')->nullable();
            $table->unsignedInteger('x');
            $table->unsignedInteger('y');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->timestamps();
            $table->foreign(['facility_plan_id', 'company_id'], 'plan_placement_company_fk')
                ->references(['id', 'company_id'])->on('facility_plans')->restrictOnDelete();
            $table->foreign(['asset_id', 'company_id'], 'plan_placement_asset_fk')
                ->references(['id', 'company_id'])->on('assets')->restrictOnDelete();
            $table->foreign(['child_node_id', 'company_id'], 'plan_placement_child_fk')
                ->references(['id', 'company_id'])->on('facility_nodes')->restrictOnDelete();
            $table->unique(['facility_plan_id', 'asset_id'], 'plan_asset_once_unique');
            $table->unique(['facility_plan_id', 'child_node_id'], 'plan_child_once_unique');
            $table->index(['company_id', 'facility_plan_id'], 'plan_placement_company_idx');
        });

        // A placement represents exactly one physical object, not an ambiguous pair.
        // SQLite's ALTER TABLE cannot add named CHECKs, so model/controller validation
        // provides the same rule in SQLite tests.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE facility_plan_placements ADD CONSTRAINT plan_placement_one_target_chk CHECK ((asset_id IS NULL) <> (child_node_id IS NULL))');
            DB::statement('ALTER TABLE facility_plans ADD CONSTRAINT plan_canvas_bounds_chk CHECK (canvas_width BETWEEN 600 AND 2400 AND canvas_height BETWEEN 400 AND 1800)');
            DB::statement('ALTER TABLE facility_plan_placements ADD CONSTRAINT plan_rect_positive_chk CHECK (width BETWEEN 32 AND 1000 AND height BETWEEN 32 AND 900)');
        }

        Schema::create('facility_plan_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('facility_plan_id');
            $table->string('event_type', 40);
            $table->unsignedInteger('version');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 255);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign(['facility_plan_id', 'company_id'], 'plan_event_company_fk')
                ->references(['id', 'company_id'])->on('facility_plans')->restrictOnDelete();
            $table->unique(['facility_plan_id', 'version'], 'plan_event_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_plan_events');
        Schema::dropIfExists('facility_plan_placements');
        Schema::dropIfExists('facility_plans');
    }
};
