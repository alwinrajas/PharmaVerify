<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('audit_line_id')->nullable();
            $table->unsignedBigInteger('audit_id')->nullable();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->unsignedBigInteger('item_stock_id')->nullable();
            $table->string('product_code', 60)->nullable();
            $table->string('barcode', 60)->nullable();
            $table->string('description', 300)->nullable();
            $table->string('batch', 60)->default('');
            $table->decimal('old_system_qty', 18, 3)->default(0);
            $table->decimal('physical_qty', 18, 3)->default(0);
            $table->decimal('variance_qty', 18, 3)->default(0);
            $table->decimal('new_system_qty', 18, 3)->default(0);
            $table->string('reason', 500)->nullable();
            $table->foreignId('adjusted_by')->nullable()->constrained('users');
            $table->timestamp('adjusted_at')->nullable();
            $table->timestamps();

            $table->index('shop_id');
            $table->index('audit_id');
            $table->index('adjusted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};
