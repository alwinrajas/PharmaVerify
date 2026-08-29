<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One stock-take cycle for one shop.
 *
 * A stock take was previously a loose row per counted line with nothing tying
 * the lines of one sweep together. The handheld models it as a session with its
 * own reference — `STK-ddMMyyyy-NNNN`, monotonic per shop — so this table gives
 * the same shape a home here and lets an imported take keep the reference the
 * operator saw on the device.
 *
 * Uniqueness on (shop_id, take_ref) is safe to enforce here, unlike on audits:
 * this table starts empty, and stock-take numbering has no device dimension to
 * make one reference legitimately repeat within a shop.
 *
 * `deleted_at` is a withdrawal, not a removal. A number is evidence that a
 * count happened, so the row survives being withdrawn and the next number is
 * taken from the highest ever issued — trashed rows included. Reissuing a
 * number would make two different counts indistinguishable in a report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_take_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('take_ref', 30);
            /** The numeric tail of the reference, monotonic within the shop. */
            $table->unsignedInteger('take_number');
            $table->date('take_date');
            $table->string('status', 25)->default('in_progress');
            $table->unsignedInteger('item_count')->default(0);
            /** 'web' when raised here, 'excel' when imported from a device. */
            $table->string('source', 20)->default('web');
            $table->string('counted_by_name', 150)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['shop_id', 'take_ref'], 'stock_take_session_ref_unique');
            $table->index(['shop_id', 'status']);
            $table->index('take_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_take_sessions');
    }
};
