<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained('audits')->cascadeOnDelete();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('item_stock_id')->nullable();
            $table->string('product_code', 60)->nullable();
            $table->string('barcode', 60)->nullable();
            $table->string('description', 300)->nullable();
            $table->decimal('system_qty', 18, 3)->default(0);
            $table->decimal('physical_qty', 18, 3)->default(0);
            $table->decimal('variance_qty', 18, 3)->default(0);
            $table->string('uom', 20)->default('EA');
            $table->decimal('price', 18, 4)->default(0);
            $table->string('batch', 60)->default('');
            $table->date('expiry_date')->nullable();
            $table->string('shelf_location', 100)->nullable();
            $table->boolean('is_unknown_item')->default(false);
            $table->string('verification_status', 20)->default('pending');
            $table->string('adjustment_status', 20)->default('not_adjusted');
            $table->foreignId('verified_by')->nullable()->constrained('users');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('adjusted_at')->nullable();
            $table->string('remarks', 500)->nullable();
            $table->timestamps();

            $table->index('audit_id');
            $table->index(['shop_id', 'barcode'], 'audit_line_shop_barcode_idx');
            $table->index('product_code');
            $table->index('verification_status');
            $table->index('adjustment_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_lines');
    }
};
