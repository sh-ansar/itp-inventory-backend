<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_sessions', function (Blueprint $table): void {
            $table->foreignUuid('scope_facility_node_id')->nullable();
            $table->string('scope_facility_node_name', 200)->nullable();
            $table->foreign(['scope_facility_node_id', 'company_id'], 'stocktake_facility_same_tenant_fk')
                ->references(['id', 'company_id'])->on('facility_nodes')->restrictOnDelete();
        });
        Schema::table('inventory_session_items', function (Blueprint $table): void {
            $table->foreignUuid('expected_facility_node_id')->nullable();
            $table->string('expected_facility_node_name', 200)->nullable();
            $table->foreignUuid('actual_facility_node_id')->nullable();
            $table->string('actual_facility_node_name', 200)->nullable();
            $table->foreign(['actual_facility_node_id', 'company_id'], 'stockitem_actual_facility_tenant_fk')
                ->references(['id', 'company_id'])->on('facility_nodes')->restrictOnDelete();
            $table->foreign(['expected_facility_node_id', 'company_id'], 'stockitem_facility_same_tenant_fk')
                ->references(['id', 'company_id'])->on('facility_nodes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_session_items', function (Blueprint $table): void {
            $table->dropForeign('stockitem_actual_facility_tenant_fk');
            $table->dropForeign('stockitem_facility_same_tenant_fk');
            $table->dropColumn(['expected_facility_node_id', 'expected_facility_node_name', 'actual_facility_node_id', 'actual_facility_node_name']);
        });
        Schema::table('inventory_sessions', function (Blueprint $table): void {
            $table->dropForeign('stocktake_facility_same_tenant_fk');
            $table->dropColumn(['scope_facility_node_id', 'scope_facility_node_name']);
        });
    }
};
