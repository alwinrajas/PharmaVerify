<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->foreignId('stock_import_id')->nullable()->constrained('stock_imports')->nullOnDelete();
            $table->string('product_code', 60);
            $table->string('barcode', 60)->nullable();
            $table->string('description', 300);
            $table->decimal('system_qty', 18, 3)->default(0);
            $table->string('uom', 20)->default('EA');
            $table->decimal('price', 18, 4)->default(0);
            $table->string('batch', 60)->default('');
            $table->date('expiry_date')->nullable();
            $table->string('shelf_location', 100)->nullable();
            $table->string('verification_status', 20)->default('not_verified');
            $table->timestamps();

            $table->unique(['shop_id', 'product_code', 'batch'], 'item_stock_identity_unique');
            $table->index(['shop_id', 'barcode'], 'item_stock_shop_barcode_idx');
            $table->index('verification_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_stocks');
    }
};
