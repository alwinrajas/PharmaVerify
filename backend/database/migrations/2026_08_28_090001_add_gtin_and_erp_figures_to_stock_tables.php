<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns the confirmed Stock Report mapping needs.
 *
 * `gtin` is separate from `barcode` on purpose. The report carries two
 * unrelated identifier systems — the 14-digit GLOBALTRADEITEMNUMBER printed on
 * the carton, and a 7-digit internal ITEMBARCODE held per batch. The business
 * has confirmed the GTIN is what the handheld scans, so it gets its own column
 * and its own index rather than the two being merged into one field where the
 * winner depends on which sheet happened to carry a value.
 *
 * `whole_qty`, `factor` and `total_cost` are stored to four decimal places.
 * Whole quantity is genuinely fractional in the source data (0.04, 0.24, 0.33,
 * 1.38 all occur), so it must never be rounded to a whole number. `total_cost`
 * is stored because the ERP figure cannot be reconstructed from quantity times
 * unit cost — 140 rows of the supplied report disagree with any such formula —
 * so the supplied value is authoritative and is kept exactly as given.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('gtin', 20)->nullable()->after('barcode');
            $table->index('gtin');
        });

        Schema::table('item_stocks', function (Blueprint $table) {
            $table->string('gtin', 20)->nullable()->after('barcode');
            $table->decimal('whole_qty', 18, 4)->nullable()->after('system_qty');
            $table->decimal('factor', 18, 4)->nullable()->after('whole_qty');
            $table->decimal('total_cost', 18, 4)->nullable()->after('price');

            $table->index(['shop_id', 'gtin'], 'item_stock_shop_gtin_idx');
        });
    }

    public function down(): void
    {
        Schema::table('item_stocks', function (Blueprint $table) {
            $table->dropIndex('item_stock_shop_gtin_idx');
            $table->dropColumn(['gtin', 'whole_qty', 'factor', 'total_cost']);
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex(['gtin']);
            $table->dropColumn('gtin');
        });
    }
};
