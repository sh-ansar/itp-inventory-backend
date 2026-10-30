<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
 public function up(): void {
  Schema::table('users', fn(Blueprint $table) => $table->boolean('is_active')->default(true));
  Schema::create('companies', function(Blueprint $table) {
   $table->uuid('id')->primary();
   $table->string('name', 255);
   $table->timestamps();
  });
  Schema::create('company_memberships', function(Blueprint $table) {
   $table->id();
   $table->foreignId('user_id')->constrained()->cascadeOnDelete();
   $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
   $table->string('role_code', 48);
   $table->unique(['user_id','company_id']);
   $table->timestamps();
  });
  Schema::create('assets', function(Blueprint $table) {
   $table->uuid('id')->primary();
   $table->uuid('public_id')->unique();
   $table->foreignUuid('company_id')->constrained('companies');
   $table->string('inventory_number', 100);
   $table->string('name');
   $table->string('serial_number', 160)->nullable();
   $table->string('status', 48)->default('active');
   $table->timestamps();
   $table->unique(['company_id','inventory_number']);
   $table->index(['company_id','status']);
  });
 }
 public function down(): void {
  Schema::dropIfExists('assets');
  Schema::dropIfExists('company_memberships');
  Schema::dropIfExists('companies');
  Schema::table('users', fn(Blueprint $table) => $table->dropColumn('is_active'));
 }
};