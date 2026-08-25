<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('product_code', 60)->unique();
            $table->string('barcode', 60)->nullable();
            $table->string('description', 300);
            $table->string('generic_name', 200)->nullable();
            $table->string('manufacturer', 200)->nullable();
            $table->string('uom', 20)->default('EA');
            $table->decimal('price', 18, 4)->default(0);
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('barcode');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
