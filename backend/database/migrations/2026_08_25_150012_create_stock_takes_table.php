<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_takes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->unsignedBigInteger('audit_id')->nullable();
            $table->unsignedBigInteger('audit_line_id')->nullable();
            $table->string('barcode', 60)->nullable();
            $table->string('product_code', 60)->nullable();
            $table->string('description', 300);
            $table->decimal('physical_qty', 18, 3)->default(0);
            $table->string('uom', 20)->default('EA');
            $table->string('batch', 60)->default('');
            $table->date('expiry_date')->nullable();
            $table->string('shelf_location', 100)->nullable();
            $table->string('status', 20)->default('recorded');
            $table->string('remarks', 500)->nullable();
            $table->foreignId('taken_by')->nullable()->constrained('users');
            $table->timestamp('taken_at')->nullable();
            $table->timestamps();

            $table->index('shop_id');
            $table->index('barcode');
            $table->index('taken_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_takes');
    }
};
