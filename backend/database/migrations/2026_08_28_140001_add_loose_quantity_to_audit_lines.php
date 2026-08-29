<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loose quantity, and what the source file believed the ERP held.
 *
 * `loose_qty` is stock counted outside a full pack. It is counted *alongside*
 * the whole units rather than carved out of them — a pack that is entirely
 * broken open is 0 physical and 5 loose, which is a legal count — so the two
 * figures are stored separately and neither is ever folded into the other.
 *
 * `source_system_qty` records the system quantity a handheld export claimed,
 * which is not necessarily the figure PharmaVerify holds: the device works from
 * its own copy of the ERP data, taken at its own moment. Keeping both makes the
 * drift between the two visible instead of silently picking a winner.
 *
 * Both default in a way that leaves every existing row unchanged: `loose_qty`
 * is 0, so the variance formula reduces to what it computed before, and
 * `source_system_qty` is null, meaning "no file said otherwise".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_lines', function (Blueprint $table) {
            $table->decimal('loose_qty', 18, 3)->default(0)->after('physical_qty');
            $table->decimal('source_system_qty', 18, 3)->nullable()->after('system_qty');
        });
    }

    public function down(): void
    {
        Schema::table('audit_lines', function (Blueprint $table) {
            $table->dropColumn(['loose_qty', 'source_system_qty']);
        });
    }
};
