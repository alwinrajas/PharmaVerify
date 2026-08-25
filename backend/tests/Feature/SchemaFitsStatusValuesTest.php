<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\AuditLine;
use App\Models\FinalOutput;
use App\Models\HhtSubmission;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every status value the application writes must fit the column that stores it.
 *
 * The feature tests run on SQLite, which accepts an over-long string without
 * complaint, so a value that outgrows its column passes every other test and
 * then fails on MySQL or SQL Server in production — as `completed_with_errors`
 * did against a 20-character column.
 *
 * SQLite also reports no length through schema introspection, so the check reads
 * the declared length straight from the migration. That is the thing we actually
 * want to assert, and it behaves identically wherever the suite runs.
 */
class SchemaFitsStatusValuesTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: array<int, string>}>
     */
    public static function statusColumns(): array
    {
        return [
            'stock import status' => ['stock_imports', 'status', [
                'pending', 'processing', 'completed', 'completed_with_errors', 'failed',
            ]],
            'audit status' => ['audits', 'status', [
                Audit::STATUS_SUBMITTED,
                Audit::STATUS_IN_VERIFICATION,
                Audit::STATUS_VERIFIED,
                Audit::STATUS_ADJUSTED,
                Audit::STATUS_CLOSED,
            ]],
            'submission status' => ['hht_submissions', 'status', [
                HhtSubmission::STATUS_ACCEPTED,
                HhtSubmission::STATUS_DUPLICATE,
                HhtSubmission::STATUS_REJECTED,
            ]],
            'audit line verification' => ['audit_lines', 'verification_status', [
                AuditLine::VERIFICATION_PENDING,
                AuditLine::VERIFICATION_VERIFIED,
            ]],
            'audit line adjustment' => ['audit_lines', 'adjustment_status', [
                AuditLine::ADJUSTMENT_NOT_ADJUSTED,
                AuditLine::ADJUSTMENT_ADJUSTED,
            ]],
            'item stock verification' => ['item_stocks', 'verification_status', [
                'not_verified', 'verified', 'adjusted',
            ]],
            'final output onedrive' => ['final_outputs', 'onedrive_status', [
                FinalOutput::ONEDRIVE_NOT_UPLOADED,
                FinalOutput::ONEDRIVE_UPLOADING,
                FinalOutput::ONEDRIVE_UPLOADED,
                FinalOutput::ONEDRIVE_FAILED,
            ]],
            'final output verification' => ['final_outputs', 'verification_status', [
                'verified', 'partially_verified', 'pending',
            ]],
            'final output adjustment' => ['final_outputs', 'adjustment_status', [
                'completed', 'pending',
            ]],
            'stock take status' => ['stock_takes', 'status', ['recorded']],
            'user status' => ['users', 'status', ['active', 'inactive']],
            'shop status' => ['shops', 'status', ['active', 'inactive']],
            'item status' => ['items', 'status', ['active', 'inactive']],
            'device status' => ['devices', 'status', ['active', 'inactive']],
        ];
    }

    /**
     * @param  array<int, string>  $values
     */
    #[DataProvider('statusColumns')]
    public function test_status_values_fit_their_column(string $table, string $column, array $values): void
    {
        $length = $this->declaredLength($table, $column);

        foreach ($values as $value) {
            $this->assertLessThanOrEqual(
                $length,
                strlen($value),
                sprintf(
                    "'%s' is %d characters but %s.%s is declared as %d. Widen the column in its migration.",
                    $value,
                    strlen($value),
                    $table,
                    $column,
                    $length
                )
            );
        }
    }

    /** The length the migration declares for a string column. */
    private function declaredLength(string $table, string $column): int
    {
        $files = glob(database_path('migrations/*.php')) ?: [];

        foreach ($files as $file) {
            $source = file_get_contents($file) ?: '';

            if (! str_contains($source, "Schema::create('{$table}'")) {
                continue;
            }

            if (preg_match("/->string\('".preg_quote($column, '/')."',\s*(\d+)\s*\)/", $source, $matches)) {
                return (int) $matches[1];
            }

            $this->fail("{$table}.{$column} is not declared as a string with an explicit length.");
        }

        $this->fail("No migration creates the table {$table}.");
    }
}
