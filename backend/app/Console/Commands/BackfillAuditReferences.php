<?php

namespace App\Console\Commands;

use App\Models\Audit;
use App\Support\SessionReference;
use Illuminate\Console\Command;

/**
 * Gives audits that predate the handheld reference format one of their own.
 *
 * Purely display enrichment. `audit_number` remains the identity and nothing
 * keys off `audit_ref` for these rows, so if this were never run the
 * application would still work — {@see Audit::reference()} derives the same
 * string on the fly. Storing it simply lets a search match it.
 *
 * The derived reference is *not* unique within a shop, and that is correct
 * rather than a defect: PharmaVerify numbers per shop and device, so four
 * devices counting shop P001 on the same day all carry audit number 1 and all
 * derive `AUD-<that day>-0001`. The column is indexed but not constrained for
 * exactly this reason.
 *
 * Idempotent: a row that already holds the reference it would be given is
 * skipped, so re-running changes nothing.
 *
 *   php artisan audits:backfill-references --dry-run
 *   php artisan audits:backfill-references
 */
class BackfillAuditReferences extends Command
{
    protected $signature = 'audits:backfill-references
                            {--dry-run : Report what would change without writing anything}
                            {--overwrite : Also rewrite references that are already set}
                            {--chunk=500 : Rows to process per batch}';

    protected $description = 'Derive AUD-ddMMyyyy-NNNN references for audits that have none.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $overwrite = (bool) $this->option('overwrite');
        $chunk = max(50, (int) $this->option('chunk'));

        $this->info($dryRun ? 'Dry run — reading only, nothing will be written.' : 'Backfilling audit references.');

        $read = 0;
        $written = 0;
        $skipped = 0;
        $shared = [];

        Audit::query()
            ->select(['id', 'shop_id', 'audit_number', 'audit_date', 'audit_ref', 'created_at'])
            ->when(! $overwrite, fn ($query) => $query->whereNull('audit_ref'))
            ->orderBy('id')
            ->chunkById($chunk, function ($audits) use (&$read, &$written, &$skipped, &$shared, $dryRun) {
                foreach ($audits as $audit) {
                    $read++;

                    $reference = SessionReference::forAudit(
                        $audit->audit_date ?? $audit->created_at ?? now(),
                        (int) $audit->audit_number
                    );

                    if ($audit->audit_ref === $reference) {
                        $skipped++;

                        continue;
                    }

                    // Recorded, not prevented: several devices at one shop
                    // sharing a reference is valid data here.
                    $key = $audit->shop_id.'|'.$reference;
                    $shared[$key] = ($shared[$key] ?? 0) + 1;

                    if ($dryRun) {
                        $written++;

                        continue;
                    }

                    Audit::whereKey($audit->id)->update(['audit_ref' => $reference]);
                    $written++;
                }
            });

        $repeated = count(array_filter($shared, fn (int $count) => $count > 1));

        $this->newLine();
        $this->table(
            ['Read', 'Already correct', $dryRun ? 'Would set' : 'Set', 'References shared within a shop'],
            [[$read, $skipped, $written, $repeated]]
        );

        if ($repeated > 0) {
            $this->comment(sprintf(
                '%d reference(s) are shared by more than one audit of the same shop. That is expected: PharmaVerify '
                .'numbers per shop and device, so several devices counting one shop on one day derive the same '
                .'reference. Identity remains shop + device + audit number.',
                $repeated
            ));
        }

        if ($dryRun) {
            $this->comment('Nothing was written. Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }
}
