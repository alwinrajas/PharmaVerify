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
            $table->foreignId('audit_line_id')->nullable()->constrained('audit_lines')->nullOnDelete();
            $table->foreignId('audit_id')->nullable()->constrained('audits')->nullOnDelete();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('item_stock_id')->nullable()->constrained('item_stocks')->nullOnDelete();
            $table->string('product_code', 60)->nullable();
            $table->string('barcode', 60)->nullable();
            $table->string('description', 300)->nullable();
            $table->string('batch', 60)->default('');
            $table->decimal('old_system_qty', 18, 3)->default(0);
            $table->decimal('physical_qty', 18, 3)->default(0);
            $table->decimal('variance_qty', 18, 3)->default(0);
            $table->decimal('new_system_qty', 18, 3)->default(0);
            $table->string('reason', 500)->nullable();
            $table->foreignId('adjusted_by')->nullable()->constrained('users')->nullOnDelete();
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
