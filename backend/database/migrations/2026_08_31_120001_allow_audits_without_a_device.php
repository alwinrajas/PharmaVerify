<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an audit exist without a handheld behind it.
 *
 * Until now every audit arrived from a device, so `device_id` was required.
 * Counting from the browser produces an audit that genuinely has no device,
 * and inventing a placeholder one would put a fiction into the column every
 * report and export reads.
 *
 * The unique index on (shop_id, device_id, audit_number) is deliberately KEPT.
 * On SQL Server — the production engine — a composite unique index compares
 * the whole tuple, so two browser audits numbered alike in one shop still
 * collide and are refused, which is what we want. MySQL exempts any row with a
 * NULL from the check, so on the development engine that guarantee disappears;
 * the service layer therefore checks identity itself before inserting. Neither
 * engine is relied upon alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlsrv') {
            // SQL Server will not alter a column while an index references it,
            // and the foreign key has to come off too. Dropped and rebuilt in
            // the same order so the table ends up exactly as it began apart
            // from the nullability.
            DB::statement('ALTER TABLE [audits] DROP CONSTRAINT [audit_identity_unique]');

            Schema::table('audits', function (Blueprint $table) {
                $table->dropForeign(['device_id']);
            });

            DB::statement('ALTER TABLE [audits] ALTER COLUMN [device_id] BIGINT NULL');

            Schema::table('audits', function (Blueprint $table) {
                $table->foreign('device_id')->references('id')->on('devices');
                $table->unique(['shop_id', 'device_id', 'audit_number'], 'audit_identity_unique');
            });

            return;
        }

        Schema::table('audits', function (Blueprint $table) {
            // MySQL alters the column in place; the index and foreign key
            // survive it, so they are left alone.
            $table->unsignedBigInteger('device_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rows without a device cannot be represented by the old shape, and
        // guessing a device for them would be worse than refusing: their
        // counts would then appear to have come from a terminal that never
        // saw them.
        if (DB::table('audits')->whereNull('device_id')->exists()) {
            throw new RuntimeException(
                'Audits counted in the browser have no device and cannot be rolled back into a schema that requires one. Remove or reassign them first.'
            );
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlsrv') {
            DB::statement('ALTER TABLE [audits] DROP CONSTRAINT [audit_identity_unique]');

            Schema::table('audits', function (Blueprint $table) {
                $table->dropForeign(['device_id']);
            });

            DB::statement('ALTER TABLE [audits] ALTER COLUMN [device_id] BIGINT NOT NULL');

            Schema::table('audits', function (Blueprint $table) {
                $table->foreign('device_id')->references('id')->on('devices');
                $table->unique(['shop_id', 'device_id', 'audit_number'], 'audit_identity_unique');
            });

            return;
        }

        Schema::table('audits', function (Blueprint $table) {
            $table->unsignedBigInteger('device_id')->nullable(false)->change();
        });
    }
};
