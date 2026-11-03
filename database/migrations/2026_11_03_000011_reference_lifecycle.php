<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_categories', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
        });
        Schema::table('asset_locations', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('asset_locations', function (Blueprint $table): void {
            $table->dropColumn(['is_active', 'version']);
        });
        Schema::table('asset_categories', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
    }
};
