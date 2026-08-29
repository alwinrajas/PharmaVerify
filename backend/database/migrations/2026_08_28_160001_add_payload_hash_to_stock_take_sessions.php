<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fingerprint of the workbook a cycle was imported from.
 *
 * Audits already have somewhere to record this — `hht_submissions` has carried
 * a payload hash since the JSON endpoint was built — but a stock take has no
 * equivalent ledger. Without a hash, re-uploading the same file could not be
 * told apart from uploading a corrected one, and the two need opposite
 * answers: the first is a no-op, the second a refusal.
 *
 * Null for a cycle raised in the web application, which came from no file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_take_sessions', function (Blueprint $table) {
            $table->string('payload_hash', 64)->nullable()->after('source');
            $table->string('file_name', 255)->nullable()->after('payload_hash');
        });
    }

    public function down(): void
    {
        Schema::table('stock_take_sessions', function (Blueprint $table) {
            $table->dropColumn(['payload_hash', 'file_name']);
        });
    }
};
