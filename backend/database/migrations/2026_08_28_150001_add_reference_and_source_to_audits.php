<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The handheld's own audit reference, and how an audit reached us.
 *
 * The device numbers its cycles `AUD-ddMMyyyy-NNNN`, monotonic per shop and
 * never reused. PharmaVerify numbers per shop *and device*, as an integer. The
 * two are kept side by side rather than one replacing the other:
 * `audit_number` stays the identity — it is in the unique index, the HHT JSON
 * contract and the audit-number report — and `audit_ref` carries the device's
 * text reference for display and for matching an imported file.
 *
 * The index on (shop_id, audit_ref) is deliberately **not unique**, which is a
 * correction to the original design. PharmaVerify's numbering is partitioned by
 * device, so a shop legitimately holds several audits with the same number on
 * the same day — one per device — and every one of them derives the same
 * reference when backfilled. A unique constraint would reject data that is
 * already valid. Uniqueness for device-sourced references is enforced in the
 * importer, where the shop and the reference arrive together and a clash can be
 * reported in words rather than as a constraint violation.
 *
 * `source` records which path created the audit: the JSON endpoint or an
 * uploaded workbook. Existing rows are all 'api', which is what they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audits', function (Blueprint $table) {
            $table->string('audit_ref', 30)->nullable()->after('audit_number');
            $table->string('source', 20)->default('api')->after('status');

            $table->index(['shop_id', 'audit_ref'], 'audit_shop_ref_idx');
        });
    }

    public function down(): void
    {
        Schema::table('audits', function (Blueprint $table) {
            $table->dropIndex('audit_shop_ref_idx');
            $table->dropColumn(['audit_ref', 'source']);
        });
    }
};
