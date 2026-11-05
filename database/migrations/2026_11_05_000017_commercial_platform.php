<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->timestamps();
        });
        Schema::create('inventory_plan_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('inventory_plans')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 24)->default('draft');
            $table->boolean('is_public')->default(false);
            $table->integer('display_order')->default(0);
            $table->json('terms');
            $table->char('sha256', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['plan_id', 'version']);
            $table->index(['status', 'is_public']);
        });
        Schema::create('inventory_company_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('company_id')->unique()->constrained('companies')->cascadeOnDelete();
            $table->foreignId('plan_version_id')->nullable()->constrained('inventory_plan_versions')->nullOnDelete();
            $table->json('snapshot');
            $table->string('status', 32)->default('pending_payment');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });
        Schema::create('inventory_platform_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('permission', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id','permission']);
        });
        Schema::create('inventory_billing_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('company_id')->nullable()->unique()->constrained('companies')->cascadeOnDelete();
            $table->string('profile_key',80)->unique();
            $table->json('details');
            $table->timestamps();
        });
        Schema::create('inventory_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('number', 40)->nullable()->unique();
            $table->string('status', 32)->default('draft');
            $table->string('currency', 3)->default('KZT');
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->date('period_from');
            $table->date('period_to');
            $table->date('due_date');
            $table->string('basis', 300);
            $table->json('items');
            $table->json('supplier_snapshot')->nullable();
            $table->json('buyer_snapshot')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id','status']);
        });
        Schema::create('inventory_payment_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('inventory_invoices')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('storage_path', 300);
            $table->string('filename', 220);
            $table->string('mime', 80);
            $table->unsignedBigInteger('size_bytes');
            $table->string('status', 24)->default('pending');
            $table->text('review_note')->nullable();
            $table->timestamps();
        });
        Schema::create('inventory_legal_versions', function (Blueprint $table) {
            $table->id();
            $table->string('type', 48);
            $table->string('locale', 4);
            $table->string('jurisdiction', 2)->default('KZ');
            $table->unsignedInteger('version');
            $table->string('title', 250);
            $table->longText('body');
            $table->string('acceptance', 24)->default('acknowledged');
            $table->boolean('required')->default(true);
            $table->timestamp('effective_at')->nullable();
            $table->string('status', 24)->default('draft');
            $table->text('approval_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->char('sha256', 64);
            $table->timestamps();
            $table->unique(['type','locale','jurisdiction','version'], 'inventory_legal_version_unique');
            $table->index(['status','locale','jurisdiction']);
        });
        Schema::create('inventory_legal_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('legal_version_id')->constrained('inventory_legal_versions');
            $table->char('sha256', 64);
            $table->string('source', 48);
            $table->string('action', 24);
            $table->string('locale', 4);
            $table->timestamps();
            $table->unique(['user_id','legal_version_id']);
        });
        Schema::create('inventory_commercial_audit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('action', 80);
            $table->string('subject', 160);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['company_id','created_at']);
        });
        Schema::create('inventory_registration_requests', function (Blueprint $table) {
            $table->id();
            $table->string('email', 255)->unique();
            $table->string('name', 255);
            $table->string('password_hash');
            $table->string('company_name', 255);
            $table->string('plan_code', 40)->nullable();
            $table->string('locale', 4)->default('ru');
            $table->json('legal_consents')->nullable();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'inventory_registration_requests','inventory_commercial_audit',
            'inventory_legal_consents','inventory_legal_versions',
            'inventory_payment_proofs','inventory_invoices',
            'inventory_billing_profiles','inventory_platform_grants',
            'inventory_company_subscriptions','inventory_plan_versions',
            'inventory_plans',
        ] as $name) Schema::dropIfExists($name);
    }
};
