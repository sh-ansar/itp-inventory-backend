<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 60);
            $table->string('name', 200);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->unique(['id', 'company_id']);
        });

        Schema::create('asset_locations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('kind', 24);
            $table->string('code', 60);
            $table->string('name', 200);
            $table->timestamps();
            $table->unique(['company_id', 'kind', 'code']);
            $table->unique(['id', 'company_id']);
        });

        Schema::table('assets', function (Blueprint $table): void {
            $table->text('description')->nullable();
            $table->uuid('category_id')->nullable();
            $table->uuid('accounting_location_id')->nullable();
            $table->uuid('physical_location_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->softDeletes();
            $table->unique(['id', 'company_id']);
            $table->foreign(['category_id', 'company_id'], 'assets_cat_company_fk')
                ->references(['id', 'company_id'])->on('asset_categories')->restrictOnDelete();
            $table->foreign(['accounting_location_id', 'company_id'], 'assets_acc_company_fk')
                ->references(['id', 'company_id'])->on('asset_locations')->restrictOnDelete();
            $table->foreign(['physical_location_id', 'company_id'], 'assets_phy_company_fk')
                ->references(['id', 'company_id'])->on('asset_locations')->restrictOnDelete();
        });

        Schema::create('asset_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->uuid('asset_id');
            $table->foreign(['asset_id', 'company_id'], 'asset_events_asset_company_fk')
                ->references(['id', 'company_id'])->on('assets')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 255);
            $table->string('event_type', 32);
            $table->unsignedInteger('version');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'asset_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_events');
        Schema::table('assets', function (Blueprint $table): void {
            $table->dropForeign('assets_cat_company_fk');
            $table->dropForeign('assets_acc_company_fk');
            $table->dropForeign('assets_phy_company_fk');
            $table->dropUnique(['id', 'company_id']);
            $table->dropColumn([
                'description', 'category_id', 'accounting_location_id',
                'physical_location_id', 'version', 'deleted_at',
            ]);
        });
        Schema::dropIfExists('asset_locations');
        Schema::dropIfExists('asset_categories');
    }
};