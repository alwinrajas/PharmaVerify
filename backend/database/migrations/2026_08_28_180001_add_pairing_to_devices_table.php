<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a handheld authenticate as itself.
 *
 * Until now a device could only reach the API by borrowing a person's token,
 * which is the wrong shape twice over: the token outlives the person's shift,
 * and every count is attributed to whoever set the terminal up rather than to
 * the terminal.
 *
 * Pairing closes that. An administrator issues a short-lived code from the
 * device's record; the handheld exchanges it once for a long-lived token of its
 * own. The code is stored hashed and cleared the moment it is spent, so a
 * screenshot of the pairing screen is worth nothing after the fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Hashed, never the code itself.
            $table->string('pairing_code_hash', 255)->nullable()->after('status');
            $table->timestamp('pairing_expires_at')->nullable()->after('pairing_code_hash');
            $table->timestamp('paired_at')->nullable()->after('pairing_expires_at');
            $table->timestamp('last_seen_at')->nullable()->after('last_submission_at');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['pairing_code_hash', 'pairing_expires_at', 'paired_at', 'last_seen_at']);
        });
    }
};
