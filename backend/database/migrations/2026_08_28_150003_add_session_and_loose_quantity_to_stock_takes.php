<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ties a counted line to its stock-take cycle, and gives it a loose figure.
 *
 * `stock_take_session_id` is nullable on purpose, and stays that way. Every
 * stock take recorded before this point was raised by hand in the web
 * application with no cycle around it, and those rows remain valid exactly as
 * they are — a null session means "recorded ad hoc", which is the truth about
 * them rather than a gap to be filled in later. Grouping them into invented
 * sessions would consume reference numbers the handheld may go on to issue.
 *
 * `loose_qty` matches the column already on `audit_lines`: stock counted
 * outside a full pack, held separately from the whole units and never folded
 * into them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_takes', function (Blueprint $table) {
            // No ON DELETE action, deliberately. `stock_takes` and
            // `stock_take_sessions` both cascade from `shops`, so adding a
            // second cascading path from session to line gives SQL Server two
            // routes from one delete and it refuses the constraint outright:
            // "may cause cycles or multiple cascade paths". MySQL permits it,
            // which is exactly the sort of difference that only surfaces on the
            // production engine.
            //
            // Nothing is lost by leaving it out. A cycle is withdrawn by a soft
            // delete and never removed, so the case this action would have
            // handled does not arise.
            $table->foreignId('stock_take_session_id')
                ->nullable()
                ->after('shop_id')
                ->constrained('stock_take_sessions');

            $table->decimal('loose_qty', 18, 3)->default(0)->after('physical_qty');
        });
    }

    public function down(): void
    {
        Schema::table('stock_takes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_take_session_id');
            $table->dropColumn('loose_qty');
        });
    }
};
