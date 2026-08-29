<?php

namespace App\Console\Commands;

use App\Models\AuditLine;
use App\Models\StockAdjustment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Brings stored variance figures onto the confirmed convention.
 *
 * The business confirmed on 2026-08-28 that variance is
 * `System - (Physical + Loose)`, which is the negation of what this application
 * stored before. Every historical figure is therefore on the wrong side of
 * zero until this has run.
 *
 * It **recomputes** from the quantities still held on each row rather than
 * flipping the sign, and the difference matters: recomputing is idempotent, so
 * running it twice is harmless, whereas negating twice silently restores the
 * wrong values. For an operation that touches every audit line ever recorded,
 * a safe re-run is worth more than a shorter query.
 *
 * The inputs it reads — system, physical, loose, old system quantity — are
 * never written, so the operation is also reversible: recomputing under the
 * old formula would put everything back.
 *
 *   php artisan variance:recompute --dry-run    what would change, changes nothing
 *   php artisan variance:recompute              apply
 */
class RecomputeVariance extends Command
{
    protected $signature = 'variance:recompute
                            {--dry-run : Report what would change without writing anything}
                            {--chunk=500 : Rows to process per batch}';

    protected $description = 'Recompute stored variance figures as System - (Physical + Loose).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(50, (int) $this->option('chunk'));

        $this->info($dryRun
            ? 'Dry run — reading only, nothing will be written.'
            : 'Recomputing variance. This rewrites a stored figure on historical records.');
        $this->newLine();

        $lines = $this->recomputeAuditLines($dryRun, $chunk);
        $adjustments = $this->recomputeAdjustments($dryRun, $chunk);

        $this->newLine();
        $this->table(
            ['Table', 'Rows read', 'Rows differing', $dryRun ? 'Would change' : 'Changed'],
            [
                ['audit_lines', $lines['read'], $lines['differing'], $dryRun ? $lines['differing'] : $lines['written']],
                ['stock_adjustments', $adjustments['read'], $adjustments['differing'], $dryRun ? $adjustments['differing'] : $adjustments['written']],
            ]
        );

        if ($dryRun) {
            $this->newLine();
            $this->comment('Nothing was written. Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{read: int, differing: int, written: int}
     */
    private function recomputeAuditLines(bool $dryRun, int $chunk): array
    {
        $read = 0;
        $differing = 0;
        $written = 0;

        AuditLine::query()
            ->select(['id', 'system_qty', 'physical_qty', 'loose_qty', 'variance_qty'])
            ->orderBy('id')
            ->chunkById($chunk, function ($rows) use (&$read, &$differing, &$written, $dryRun) {
                $updates = [];

                foreach ($rows as $line) {
                    $read++;

                    $correct = AuditLine::calculateVariance(
                        (float) $line->physical_qty,
                        (float) $line->loose_qty,
                        (float) $line->system_qty
                    );

                    // Compare as rounded floats: the column is decimal(18,3)
                    // and a string comparison would call 0.000 and -0.000
                    // different.
                    if (abs($correct - (float) $line->variance_qty) < 0.0005) {
                        continue;
                    }

                    $differing++;
                    $updates[$line->id] = $correct;
                }

                if ($updates === [] || $dryRun) {
                    return;
                }

                DB::transaction(function () use ($updates, &$written) {
                    foreach ($updates as $id => $variance) {
                        AuditLine::whereKey($id)->update(['variance_qty' => $variance]);
                        $written++;
                    }
                });
            });

        return ['read' => $read, 'differing' => $differing, 'written' => $written];
    }

    /**
     * Adjustments carry their own copy of the variance, derived from the two
     * quantities recorded beside it. Loose did not exist when any historical
     * adjustment was posted, so it is zero for all of them.
     *
     * @return array{read: int, differing: int, written: int}
     */
    private function recomputeAdjustments(bool $dryRun, int $chunk): array
    {
        $read = 0;
        $differing = 0;
        $written = 0;

        StockAdjustment::query()
            ->select(['id', 'old_system_qty', 'physical_qty', 'variance_qty'])
            ->orderBy('id')
            ->chunkById($chunk, function ($rows) use (&$read, &$differing, &$written, $dryRun) {
                $updates = [];

                foreach ($rows as $adjustment) {
                    $read++;

                    $correct = AuditLine::calculateVariance(
                        (float) $adjustment->physical_qty,
                        0.0,
                        (float) $adjustment->old_system_qty
                    );

                    if (abs($correct - (float) $adjustment->variance_qty) < 0.0005) {
                        continue;
                    }

                    $differing++;
                    $updates[$adjustment->id] = $correct;
                }

                if ($updates === [] || $dryRun) {
                    return;
                }

                DB::transaction(function () use ($updates, &$written) {
                    foreach ($updates as $id => $variance) {
                        StockAdjustment::whereKey($id)->update(['variance_qty' => $variance]);
                        $written++;
                    }
                });
            });

        return ['read' => $read, 'differing' => $differing, 'written' => $written];
    }
}
