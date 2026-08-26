<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Stock Report identifies a branch by its source-system warehouse code
 * (INVENTLOCATIONID, e.g. P001), which is not the same as the shop code the
 * business uses here. This column carries that mapping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('ax_location_id', 50)->nullable();
            $table->unique('ax_location_id', 'shops_ax_location_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropUnique('shops_ax_location_id_unique');
            $table->dropColumn('ax_location_id');
        });
    }
};
