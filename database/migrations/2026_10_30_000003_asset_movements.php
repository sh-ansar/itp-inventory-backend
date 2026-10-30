<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('asset_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('asset_id');
            $table->uuid('from_location_id')->nullable();
            $table->uuid('to_location_id');
            $table->string('from_location_name', 200)->nullable();
            $table->string('to_location_name', 200);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 255);
            $table->text('reason');
            $table->unsignedInteger('version');
            $table->timestamp('created_at')->useCurrent();
            $table->foreign(['asset_id','company_id'], 'asset_moves_asset_company_fk')
                ->references(['id','company_id'])->on('assets')->restrictOnDelete();
            $table->foreign(['from_location_id','company_id'], 'asset_moves_from_company_fk')
                ->references(['id','company_id'])->on('asset_locations')->restrictOnDelete();
            $table->foreign(['to_location_id','company_id'], 'asset_moves_to_company_fk')
                ->references(['id','company_id'])->on('asset_locations')->restrictOnDelete();
            $table->index(['company_id','asset_id','version'], 'asset_moves_history_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_movements');
    }
};