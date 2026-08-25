<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Grammars\SqlServerGrammar as SqlServerQueryGrammar;
use Illuminate\Database\Query\Processors\SqlServerProcessor;
use Illuminate\Database\Schema\Grammars\SqlServerGrammar as SqlServerSchemaGrammar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Writes the SQL Server DDL for the current migrations without needing a
 * SQL Server connection to be available.
 *
 * The migrations remain the source of truth; this renders them through the
 * SQL Server grammar so a DBA has a script to review before deployment.
 */
class GenerateSqlServerSchema extends Command
{
    protected $signature = 'pharmaverify:sqlsrv-schema {--output=schema-sqlserver.sql}';

    protected $description = 'Render the migration schema as a Microsoft SQL Server script';

    public function handle(): int
    {
        $connection = DB::connection();

        $original = [
            $connection->getSchemaGrammar(),
            $connection->getQueryGrammar(),
            $connection->getPostProcessor(),
        ];

        // Swap in the SQL Server grammar while staying on the working
        // connection, so no sqlsrv driver is needed to produce the script.
        $connection->setSchemaGrammar(new SqlServerSchemaGrammar($connection));
        $connection->setQueryGrammar(new SqlServerQueryGrammar($connection));
        $connection->setPostProcessor(new SqlServerProcessor);

        $statements = [];

        try {
            $queries = $connection->pretend(function () {
                // Run each migration's `up()` against the pretending connection.
                // Going through the files directly avoids the migrator's own
                // bookkeeping, which is not part of the schema.
                foreach ($this->migrationFiles() as $file) {
                    $migration = require $file;

                    if (is_object($migration) && method_exists($migration, 'up')) {
                        $migration->up();
                    }
                }
            });

            foreach ($queries as $query) {
                $sql = trim($query['query']);

                // Migration bookkeeping is not part of the schema script.
                if (str_contains($sql, 'migrations') && str_starts_with(strtolower($sql), 'insert')) {
                    continue;
                }

                if (str_starts_with(strtolower($sql), 'select')) {
                    continue;
                }

                $statements[] = $sql;
            }
        } finally {
            [$schemaGrammar, $queryGrammar, $processor] = $original;
            $connection->setSchemaGrammar($schemaGrammar);
            $connection->setQueryGrammar($queryGrammar);
            $connection->setPostProcessor($processor);
        }

        if ($statements === []) {
            $this->error(
                'No statements were produced. The migration table already records every migration as run; '
                .'use a scratch database, or run `php artisan migrate:reset` first.'
            );

            return self::FAILURE;
        }

        $path = base_path('../database/sql/'.$this->option('output'));
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $this->header().implode(";\n\n", $statements).";\n");

        $this->info(sprintf('Wrote %d statement(s) to %s', count($statements), realpath($path) ?: $path));

        return self::SUCCESS;
    }

    /**
     * Migration files in the order they would be applied.
     *
     * @return array<int, string>
     */
    private function migrationFiles(): array
    {
        $files = glob(database_path('migrations/*.php')) ?: [];
        sort($files);

        return $files;
    }

    private function header(): string
    {
        return "-- ---------------------------------------------------------------------\n"
            ."-- PharmaVerify - Microsoft SQL Server schema\n"
            ."--\n"
            ."-- Generated from the Laravel migrations, which remain the source of\n"
            ."-- truth. This script exists so the schema can be reviewed and applied\n"
            ."-- by a DBA where `php artisan migrate` is not run directly.\n"
            ."--\n"
            ."-- Regenerate with:  php artisan pharmaverify:sqlsrv-schema\n"
            ."-- ---------------------------------------------------------------------\n\n\n";
    }
}
