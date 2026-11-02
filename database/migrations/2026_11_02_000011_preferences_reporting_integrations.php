<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_table_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('view_key', 40);
            $table->json('settings');
            $table->timestamps();
            $table->unique(['company_id', 'user_id', 'view_key'], 'inventory_pref_tenant_user_view');
        });

        // Keys are never stored in plaintext. Each key is bound to exactly one company.
        Schema::create('inventory_integration_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('system_code', 40);
            $table->string('name', 120);
            $table->char('token_hash', 64)->unique();
            $table->json('abilities');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'system_code', 'revoked_at'], 'inventory_int_key_scope_idx');
        });

        // Reserved for a future reviewed import. No automatic 1C writes are enabled.
        Schema::create('inventory_external_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('system_code', 40);
            $table->string('entity_type', 40);
            $table->string('external_id', 180);
            $table->uuid('internal_id');
            $table->timestamps();
            // An external mapping cannot point at another company's asset.
            $table->foreign(['internal_id', 'company_id'], 'inventory_mapping_asset_company_fk')
                ->references(['id', 'company_id'])->on('assets')->restrictOnDelete();
            $table->unique(['company_id', 'system_code', 'entity_type', 'external_id'],
                'inventory_mapping_external_unique');
            $table->unique(['company_id', 'system_code', 'entity_type', 'internal_id'],
                'inventory_mapping_internal_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_external_mappings');
        Schema::dropIfExists('inventory_integration_keys');
        Schema::dropIfExists('inventory_table_preferences');
    }
};
