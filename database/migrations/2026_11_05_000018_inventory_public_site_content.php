<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('inventory_site_content', function(Blueprint $table){
            $table->id();
            $table->string('locale',4);
            $table->string('key',80);
            $table->text('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['locale','key']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('inventory_site_content');
    }
};
