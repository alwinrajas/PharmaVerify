<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Stock Report identifies a branch by its source-system warehouse code
 * (INVENTLOCATIONID, e.g. P001), which is not the same as the shop code the
 * business uses here. This column carries that mapping.
 *
 * The code must be unique across the shops that have one, but most shops have
 * none. SQL Server and PostgreSQL treat NULLs as equal in a plain unique index
 * and so permit only a single one — which would stop a second shop being
 * created without a warehouse code at all. Those engines therefore get a
 * filtered index that simply ignores the empty rows. MySQL and SQLite already
 * treat NULLs as distinct, so a plain unique index behaves correctly there.
 */
return new class extends Migration
{
    private const INDEX = 'shops_ax_location_id_unique';

    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('ax_location_id', 50)->nullable();
        });

        if ($this->supportsFilteredIndex()) {
            DB::statement(sprintf(
                'CREATE UNIQUE INDEX %s ON shops (ax_location_id) WHERE ax_location_id IS NOT NULL',
                self::INDEX
            ));

            return;
        }

        Schema::table('shops', function (Blueprint $table) {
            $table->unique('ax_location_id', self::INDEX);
        });
    }

    public function down(): void
    {
        if ($this->supportsFilteredIndex()) {
            // A filtered index is an index, not a constraint, so it is dropped
            // as one rather than through dropUnique().
            DB::statement($this->driver() === 'sqlsrv'
                ? sprintf('DROP INDEX %s ON shops', self::INDEX)
                : sprintf('DROP INDEX %s', self::INDEX));
        } else {
            Schema::table('shops', function (Blueprint $table) {
                $table->dropUnique(self::INDEX);
            });
        }

        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('ax_location_id');
        });
    }

    private function supportsFilteredIndex(): bool
    {
        return in_array($this->driver(), ['sqlsrv', 'pgsql'], true);
    }

    private function driver(): string
    {
        return Schema::getConnection()->getDriverName();
    }
};
