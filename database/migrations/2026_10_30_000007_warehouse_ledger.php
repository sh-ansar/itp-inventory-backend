<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 80);
            $table->string('name', 255);
            $table->string('unit', 16)->default('pcs');
            $table->foreignUuid('category_id')->nullable();
            $table->unsignedBigInteger('minimum_quantity_milli')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['company_id', 'code'], 'stock_company_sku_code_unique');
            $table->unique(['id', 'company_id'], 'stock_item_company_fk_target');
            $table->foreign(['category_id', 'company_id'], 'stock_category_same_tenant_fk')
                ->references(['id', 'company_id'])->on('asset_categories')->restrictOnDelete();
        });

        Schema::create('stock_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->uuid('stock_item_id');
            $table->uuid('warehouse_node_id');
            $table->unsignedBigInteger('quantity_milli')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'stock_item_id', 'warehouse_node_id'], 'stock_company_sku_warehouse_unique');
            $table->foreign(['stock_item_id', 'company_id'], 'stock_bal_item_same_tenant_fk')
                ->references(['id', 'company_id'])->on('stock_items')->restrictOnDelete();
            $table->foreign(['warehouse_node_id', 'company_id'], 'stock_bal_node_same_tenant_fk')
                ->references(['id', 'company_id'])->on('facility_nodes')->restrictOnDelete();
        });

        Schema::create('stock_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->uuid('stock_item_id');
            $table->string('operation', 20);
            $table->uuid('source_warehouse_node_id')->nullable();
            $table->uuid('destination_warehouse_node_id')->nullable();
            $table->unsignedBigInteger('quantity_milli');
            $table->unsignedBigInteger('before_source_milli')->nullable();
            $table->unsignedBigInteger('after_source_milli')->nullable();
            $table->unsignedBigInteger('before_destination_milli')->nullable();
            $table->unsignedBigInteger('after_destination_milli')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 255);
            $table->string('reason', 1000);
            $table->timestamp('created_at')->useCurrent();
            $table->foreign(['stock_item_id', 'company_id'], 'stock_tx_item_same_tenant_fk')
                ->references(['id', 'company_id'])->on('stock_items')->restrictOnDelete();
            $table->foreign(['source_warehouse_node_id', 'company_id'], 'stock_tx_src_same_tenant_fk')
                ->references(['id', 'company_id'])->on('facility_nodes')->restrictOnDelete();
            $table->foreign(['destination_warehouse_node_id', 'company_id'], 'stock_tx_dest_same_tenant_fk')
                ->references(['id', 'company_id'])->on('facility_nodes')->restrictOnDelete();
            $table->index(['company_id', 'created_at'], 'stock_tx_company_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transactions');
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('stock_items');
    }
};
